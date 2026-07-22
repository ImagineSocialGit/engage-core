<?php

namespace App\Support\Clients;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use RuntimeException;

final class ClientEnvironmentLoader
{
    /**
     * Environment keys owned by the selected client deployment.
     *
     * Root .env owns CLIENT_KEY and machine/process infrastructure. Client
     * .env owns deployment values that should follow the selected client.
     *
     * @var array<int, string>
     */
    private const CLIENT_OWNED_KEYS = [
        'APP_URL',
        'ROOT_DOMAIN',
        'WEBINAR_APP_URL',
        'CRM_APP_URL',
        'SCHEDULING_APP_URL',

        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',

        'CACHE_PREFIX',
        'REDIS_PREFIX',
        'HORIZON_PREFIX',
        'SESSION_DOMAIN',

        'DO_SPACES_KEY',
        'DO_SPACES_SECRET',
        'DO_SPACES_ENDPOINT',
        'DO_SPACES_REGION',
        'DO_SPACES_BUCKET',
        'CDN_BASE_URL',

        'MAIL_MAILER',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'EMAIL_PROVIDER',

        'FROM_EMAIL_TRANSACTIONAL',
        'FROM_NAME_TRANSACTIONAL',
        'FROM_EMAIL_MARKETING',
        'FROM_NAME_MARKETING',

        'RESEND_API_KEY',
        'RESEND_WEBHOOK_SECRET',
        'RESEND_FROM_EMAIL_TRANSACTIONAL',
        'RESEND_FROM_NAME_TRANSACTIONAL',
        'RESEND_FROM_EMAIL_MARKETING',
        'RESEND_FROM_NAME_MARKETING',

        'PERMISSION_INVITATION_PUBLIC_URL',

        'INTERNAL_NOTIFICATION_FROM_ADDRESS',
        'INTERNAL_NOTIFICATION_FROM_NAME',
        'INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL',

        'SMS_ENABLED',
        'SMS_PROVIDER',

        'TELNYX_API_KEY',
        'TELNYX_FROM',
        'TELNYX_FROM_TRANSACTIONAL',
        'TELNYX_FROM_MARKETING',
        'TELNYX_FROM_NOTIFICATIONS',
        'TELNYX_WEBHOOK_PUBLIC_KEY',

        'MESSAGING_SMS_MARKETING_PROFILE_ID',
        'MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID',

        'TWILIO_SID',
        'TWILIO_AUTH_TOKEN',
        'TWILIO_FROM',
        'TWILIO_FROM_TRANSACTIONAL',
        'TWILIO_FROM_MARKETING',
        'TWILIO_VIRTUAL_PHONE',

        'WEBINAR_PROVIDER',
        'WEBINAR_BOOKING_URL',

        'ZOOM_ACCOUNT_ID',
        'ZOOM_CLIENT_ID',
        'ZOOM_CLIENT_SECRET',
        'ZOOM_WEBHOOK_SECRET',
    ];

    public function load(string $basePath): void
    {
        $clientKey = $this->clientKey();

        if ($clientKey === null) {
            return;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $clientKey)) {
            throw new RuntimeException(
                "CLIENT_KEY [{$clientKey}] contains invalid characters."
            );
        }

        $clientDirectory = rtrim($basePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'client'
            .DIRECTORY_SEPARATOR.$clientKey;

        $environmentPath = $clientDirectory.DIRECTORY_SEPARATOR.'.env';

        if (! is_file($environmentPath)) {
            return;
        }

        $values = Dotenv::createArrayBacked(
            $clientDirectory,
            '.env',
        )->safeLoad();

        $unsupportedKeys = array_values(array_diff(
            array_keys($values),
            self::CLIENT_OWNED_KEYS,
        ));

        sort($unsupportedKeys);

        if ($unsupportedKeys !== []) {
            throw new RuntimeException(sprintf(
                'Client environment [%s] contains root-owned or unsupported key(s): %s.',
                $environmentPath,
                implode(', ', $unsupportedKeys),
            ));
        }

        /*
         * Clear every client-owned key before applying the selected client.
         *
         * This prevents stale root .env values or values from a previously loaded
         * client from leaking into the newly selected client's effective config.
         */
        foreach (self::CLIENT_OWNED_KEYS as $key) {
            $this->clearEnvironmentValue($key);
        }

        foreach ($values as $key => $value) {
            $this->setEnvironmentValue($key, $value);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function clientOwnedKeys(): array
    {
        return self::CLIENT_OWNED_KEYS;
    }

    private function clientKey(): ?string
    {
        $clientKey = Env::get('CLIENT_KEY');

        if (! is_string($clientKey)) {
            return null;
        }

        $clientKey = trim($clientKey);

        return $clientKey !== ''
            ? $clientKey
            : null;
    }

    private function clearEnvironmentValue(string $key): void
    {
        putenv($key);

        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        putenv("{$key}={$value}");

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}