<?php

namespace App\Service;

use App\Exception\MailException;
use App\Exception\ParsingException;
use App\Exception\SmsException;
use App\Model\Application;
use App\Model\Device;
use App\Model\Gateway;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class AlertManager
{
    public function __construct(
        private ApiClient $apiClient,
        private SmsClient $smsClient,
        private MailService $mailService,
        private MetricsService $metricsService,
        private TemplateService $templateService,
        private bool $applicationCheckStartDate,
        private int $gatewayLimit,
        private string $gatewayFallbackMail,
        private string $gatewayFallbackPhone,
        private string $gatewayBaseUrl,
        private int $deviceFallbackLimit,
        private string $deviceFallbackMail,
        private string $deviceFallbackPhone,
        private string $deviceMetadataFieldLimit,
        private string $deviceMetadataFieldMail,
        private string $deviceMetadataFieldPhone,
        private string $deviceBaseUrl,
    ) {
    }

    /**
     * Check the gateways.
     *
     * @param \DateTimeImmutable $now
     *   Relative time to check against
     * @param bool $filterOnStatus
     *   Filter based on the status given in configuration
     * @param string $overrideMail
     *   Override the mail-address from the API with this address
     * @param string $overridePhone
     *  Override the phone number from the API with this number
     * @param bool $noMail
     *   Do not send mails
     * @param bool $noSms
     *   Do not send SMSs
     *
     * @throws MailException
     * @throws ParsingException
     * @throws SmsException
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function checkGateways(\DateTimeImmutable $now, bool $filterOnStatus = true, string $overrideMail = '', string $overridePhone = '', bool $noMail = false, bool $noSms = false): void
    {
        $gateways = $this->apiClient->getGateways($filterOnStatus);
        foreach ($gateways as $gateway) {
            $diff = $this->timeDiffInSeconds($gateway->lastSeenAt, $now);
            if ($diff >= $this->gatewayLimit) {
                // Gateway limit for last seen is reached.
                if (!$noMail) {
                    $subject = sprintf(
                        'Gateway "%s" offline siden %s',
                        $gateway->name,
                        $gateway->lastSeenAt->format('d-m-y H:i:s')
                    );
                    $this->mailService->sendEmail(
                        to: $this->findGatewayToMailAddress($gateway, $overrideMail),
                        context: [
                            'gateway' => $gateway,
                            'diff' => $diff,
                            'since' => [
                                'hours' => floor($diff / 3600),
                                'minutes' => floor(($diff % 3600) / 60),
                            ],
                            'url' => $this->gatewayBaseUrl.$gateway->gatewayId,
                        ],
                        subject: $subject,
                        htmlTemplate: 'gateway.html.twig',
                        textTemplate: 'gateway.txt.twig',
                    );
                }

                // SMS
                if (!$noSms) {
                    $this->smsClient->send(
                        to: [$this->findGatewayPhone($gateway, $overridePhone)],
                        message: $this->templateService->renderTemplate('sms/gateway.twig', [
                            'gateway' => $gateway,
                            'since' => [
                                'hours' => floor($diff / 3600),
                                'minutes' => floor(($diff % 3600) / 60),
                            ],
                            'url' => $this->gatewayBaseUrl.$gateway->gatewayId,
                        ])
                    );
                }

                $this->metricsService->counter(
                    name: 'alter_gateway_device_notification_tiggered_total',
                    help: 'Total number of alters triggered for gateways',
                    labels: ['type' => 'info']
                );
            }
        }
    }

    /**
     * @param \DateTimeImmutable $now
     *   Relative time to check against
     * @param bool $filterOnStatus
     *   Filter based on the status given in configuration
     * @param string $overrideMail
     *   Override the mail-address from the API with this address
     * @param string $overridePhone
     *  Override the phone number from the API with this number
     * @param bool $noMail
     *   Do not send mails
     * @param bool $noSms
     *   Do not send SMSs
     *
     * @throws ClientExceptionInterface
     * @throws MailException
     * @throws ParsingException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws SmsException
     * @throws TransportExceptionInterface
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function checkApplications(\DateTimeImmutable $now, bool $filterOnStatus = true, string $overrideMail = '', string $overridePhone = '', bool $noMail = false, bool $noSms = false): void
    {
        $apps = $this->apiClient->getApplications($filterOnStatus);
        foreach ($apps as $app) {
            if ($this->skipBasedOnAppStartDate($app)) {
                continue;
            }

            foreach ($app->devices as $deviceId) {
                $this->checkDevice(
                    now: $now,
                    deviceId: $deviceId,
                    application: $app,
                    overrideMail: $overrideMail,
                    overridePhone: $overridePhone,
                    noMail: $noMail,
                    noSms: $noSms,
                );
            }
        }
    }

    /**
     * Check a device.
     *
     * @param \DateTimeImmutable $now
     *   Relative time to check against
     * @param int $deviceId
     *   The id of the device to check
     * @param Application|null $application
     *   Application that holds the devices (if available)
     * @param string $overrideMail
     *   Override the mail-address from the API with this address
     * @param string $overridePhone
     *  Override the phone number from the API with this number
     * @param bool $noMail
     *   Do not send mails
     * @param bool $noSms
     *   Do not send SMSs
     *
     * @throws ClientExceptionInterface
     * @throws MailException
     * @throws ParsingException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws SmsException
     * @throws TransportExceptionInterface
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function checkDevice(\DateTimeImmutable $now, int $deviceId, ?Application $application = null, string $overrideMail = '', string $overridePhone = '', bool $noMail = false, bool $noSms = false): void
    {
        $device = $this->apiClient->getDevice($deviceId);

        // No message sent from a device, hence no last sent to calculate diff
        // from.
        if (is_null($device->latestReceivedMessage)) {
            $this->metricsService->counter(
                name: 'alter_message_missing_total',
                help: 'Device is missing latest received message',
                labels: ['type' => 'info', 'id' => $device->id]
            );

            return;
        }

        // Check timeout.
        $limit = $device->metadata[$this->deviceMetadataFieldLimit] ?? $this->deviceFallbackLimit;
        $diff = $this->timeDiffInSeconds($device->latestReceivedMessage->sentTime, $now);
        if ($diff >= $limit) {
            // Device limit for last seen is reached.
            if (!$noMail) {
                $subject = sprintf(
                    'Enhed "%s" offline siden %s',
                    $device->name,
                    $device->latestReceivedMessage->sentTime->format('d-m-y H:i:s')
                );
                $this->mailService->sendEmail(
                    to: $this->findDeviceToMailAddress($device, $application, $overrideMail),
                    context: [
                        'device' => $device,
                        'since' => [
                            'hours' => floor($diff / 3600),
                            'minutes' => floor(($diff % 3600) / 60),
                        ],
                        'url' => sprintf($this->deviceBaseUrl, $device->applicationId, $device->id),
                    ],
                    subject: $subject,
                    htmlTemplate: 'device.html.twig',
                    textTemplate: 'device.txt.twig',
                );
            }

            // SMS
            if (!$noSms) {
                $this->smsClient->send(
                    to: [$this->findDevicePhone($device, $application, $overridePhone)],
                    message: $this->templateService->renderTemplate('sms/gateway.twig', [
                        'device' => $device,
                        'since' => [
                            'hours' => floor($diff / 3600),
                            'minutes' => floor(($diff % 3600) / 60),
                        ],
                        'url' => sprintf($this->deviceBaseUrl, $device->applicationId, $device->id),
                    ])
                );
            }

            $this->metricsService->counter(
                name: 'alter_device_notification_tiggered_total',
                help: 'Total number of alters triggered for devices',
                labels: ['type' => 'info']
            );
        }
    }

    /**
     * Get the time differences between two date objects in seconds.
     *
     * @param \DateTimeImmutable $date
     *   The first date
     * @param \DateTimeImmutable $now
     *   The next date normally "now"
     *
     * @return int
     *   The interval in seconds
     */
    private function timeDiffInSeconds(\DateTimeImmutable $date, \DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $date->getTimestamp();
    }

    /**
     * Find the "to" e-mail-address with fallback address.
     *
     * @param Gateway $gateway
     *   The gateway to use
     *
     * @return string
     *   The mail address
     */
    private function findGatewayToMailAddress(Gateway $gateway, string $overrideMail): string
    {
        if (!empty($overrideMail)) {
            return $overrideMail;
        }

        return empty($gateway->responsibleEmail) ? $this->gatewayFallbackMail : $gateway->responsibleEmail;
    }

    /**
     * Find the "to" e-mail-address with fallback address.
     *
     * @param Device $device
     *   The device to look up metadata on
     * @param Application|null $application
     *   The application to fall back to
     * @param string $overrideMail
     *   The override mail address
     *
     * @return string
     *   The mail address
     */
    private function findDeviceToMailAddress(Device $device, ?Application $application, string $overrideMail): string
    {
        if (!empty($overrideMail)) {
            return $overrideMail;
        }

        return empty($device->metadata[$this->deviceMetadataFieldMail]) ? ($application->contactEmail ?? $this->deviceFallbackMail) : $device->metadata[$this->deviceMetadataFieldMail];
    }

    /**
     * Find the phone number for a gateway.
     *
     * @param Gateway $gateway
     *   The gateway to find number for
     * @param string $overridePhone
     *   An override value for the phone number
     *
     * @return string
     *   The phone number to use, which is either the override phone, the gateway's
     *   responsible phone, or a fallback device phone
     */
    public function findGatewayPhone(Gateway $gateway, string $overridePhone): string
    {
        if (!empty($overridePhone)) {
            return $overridePhone;
        }

        return empty($gateway->responsiblePhone) ? $this->gatewayFallbackPhone : $gateway->responsiblePhone;
    }

    /**
     * Find the phone number for a device.
     *
     * @param Device $device
     *   Device to find number for
     * @param ?Application $application
     *   Application to fall back to
     * @param string $overridePhone
     *   Override number if given
     *
     * @return string
     *   The number found
     */
    public function findDevicePhone(Device $device, ?Application $application, string $overridePhone): string
    {
        if (!empty($overridePhone)) {
            return $overridePhone;
        }

        return empty($device->metadata[$this->deviceMetadataFieldPhone]) ? ($application->contactPhone ?? $this->deviceFallbackPhone) : $device->metadata[$this->deviceMetadataFieldPhone];
    }

    /**
     * Skip checking application based on start date.
     *
     * @param Application $application
     *   Application to test
     *
     * @return bool
     *   If the current time is before the application's start date, it returns
     *   true to indicate skipping, otherwise, it returns false
     */
    private function skipBasedOnAppStartDate(Application $application): bool
    {
        if ($this->applicationCheckStartDate) {
            if (is_null($application->startDate)) {
                // If no application start date is given, we need to not skip as
                // we do not have data to skip on.
                return false;
            }

            return time() <= $application->startDate->getTimestamp();
        }

        return false;
    }
}