<?php

namespace App\Modules\Webinars\Data;

use InvalidArgumentException;

readonly class WebinarRegistrationImportRow
{
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public bool $transactionalEmailConsent = false,
        public bool $transactionalSmsConsent = false,
        public bool $marketingEmailConsent = false,
        public bool $marketingSmsConsent = false,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Webinar registration import row has an invalid [email].');
        }

        return new self(
            email: $email,
            firstName: self::nullableString($row['first_name'] ?? null),
            lastName: self::nullableString($row['last_name'] ?? null),
            phone: self::nullableString($row['phone'] ?? null),
            transactionalEmailConsent: self::booleanValue($row, 'transactional_email_consent'),
            transactionalSmsConsent: self::booleanValue($row, 'transactional_sms_consent'),
            marketingEmailConsent: self::booleanValue($row, 'marketing_email_consent'),
            marketingSmsConsent: self::booleanValue($row, 'marketing_sms_consent'),
        );
    }

    /**
     * @return array<int, string>
     */
    public function acceptedTransactionalChannels(): array
    {
        return array_values(array_filter([
            $this->transactionalEmailConsent ? 'email' : null,
            $this->transactionalSmsConsent ? 'sms' : null,
        ]));
    }

    /**
     * @return array<int, string>
     */
    public function acceptedMarketingChannels(): array
    {
        return array_values(array_filter([
            $this->marketingEmailConsent ? 'email' : null,
            $this->marketingSmsConsent ? 'sms' : null,
        ]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function booleanValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException(
            "Webinar registration import row has invalid boolean value [{$value}] for [{$key}]."
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
