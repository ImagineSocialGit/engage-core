<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\BookableService;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SchedulingLocationSnapshot
{
    private const ADDRESS_FIELDS = [
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
        'formatted_address',
        'latitude',
        'longitude',
        'timezone',
        'precision',
        'confidence',
        'provider',
    ];

    private const CANONICAL_TYPES = [
        BookableService::LOCATION_TYPE_PHONE,
        BookableService::LOCATION_TYPE_VIRTUAL,
        BookableService::LOCATION_TYPE_FIXED,
        BookableService::LOCATION_TYPE_CUSTOMER_SITE,
    ];

    private function __construct(
        public string $type,
        public ?array $details,
        public bool $canonical,
    ) {}

    /**
     * @param array<string, mixed>|null $details
     */
    public static function canonical(
        string $type,
        ?array $details = null,
    ): self {
        $type = self::requiredType($type);

        if (! in_array($type, self::CANONICAL_TYPES, true)) {
            throw new InvalidArgumentException(
                "Unsupported canonical Scheduling location type [{$type}].",
            );
        }

        return new self(
            type: $type,
            details: self::canonicalDetails($type, $details),
            canonical: true,
        );
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public static function legacy(
        string $type,
        ?array $details = null,
    ): self {
        return new self(
            type: self::requiredType($type),
            details: $details,
            canonical: false,
        );
    }

    public static function fromPersisted(
        ?string $type,
        mixed $details,
    ): ?self {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        if ($details !== null && ! is_array($details)) {
            throw new InvalidArgumentException(
                'Persisted Scheduling location details must be an array or null.',
            );
        }

        $type = trim($type);

        return in_array($type, self::CANONICAL_TYPES, true)
            ? self::canonical($type, $details)
            : self::legacy($type, $details);
    }

    /**
     * @param array<string, mixed> $address
     */
    public static function fromNormalizedAddress(
        string $type,
        array $address,
        ?string $label = null,
        ?string $instructions = null,
    ): self {
        if (! in_array($type, [
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
        ], true)) {
            throw new InvalidArgumentException(
                'Normalized address snapshots require fixed or customer_site location type.',
            );
        }

        return self::canonical($type, array_filter([
            'label' => self::nullableString($label, 'label', 255),
            'instructions' => self::nullableString($instructions, 'instructions', 5000),
            'address' => $address,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function isPhysical(): bool
    {
        return in_array($this->type, [
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
        ], true);
    }

    public function isCustomerSite(): bool
    {
        return $this->type === BookableService::LOCATION_TYPE_CUSTOMER_SITE;
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self
            && $this->type === $other->type
            && $this->details === $other->details;
    }

    public function hasSameCommitmentIdentity(?self $other): bool
    {
        if (! $other instanceof self || $this->type !== $other->type) {
            return false;
        }

        if ($this->isCustomerSite() && $other->isCustomerSite()) {
            return data_get($this->details, 'address')
                === data_get($other->details, 'address');
        }

        return $this->details === $other->details;
    }

    /**
     * @return array{location_type: string, location_details: array<string, mixed>|null}
     */
    public function toColumns(): array
    {
        return [
            'location_type' => $this->type,
            'location_details' => $this->details,
        ];
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    private static function canonicalDetails(
        string $type,
        ?array $details,
    ): ?array {
        $details ??= [];

        return match ($type) {
            BookableService::LOCATION_TYPE_PHONE => self::simpleDetails(
                details: $details,
                allowed: ['label', 'instructions'],
            ),
            BookableService::LOCATION_TYPE_VIRTUAL => self::virtualDetails($details),
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE => self::physicalDetails($details),
            default => throw new InvalidArgumentException(
                "Unsupported canonical Scheduling location type [{$type}].",
            ),
        };
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $allowed
     * @return array<string, mixed>|null
     */
    private static function simpleDetails(
        array $details,
        array $allowed,
    ): ?array {
        self::assertAllowedFields($details, $allowed, 'Scheduling location details');

        $normalized = [];

        foreach (array_keys($details) as $field) {
            $value = match ($field) {
                'label' => self::optionalString($details, 'label', 255),
                'instructions' => self::optionalString($details, 'instructions', 5000),
                default => null,
            };

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>|null
     */
    private static function virtualDetails(array $details): ?array
    {
        self::assertAllowedFields(
            $details,
            ['label', 'url', 'instructions', 'provider'],
            'Virtual Scheduling location details',
        );

        $url = self::optionalString($details, 'url', 2048);

        if ($url !== null) {
            $parts = parse_url($url);
            $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
                ? strtolower($parts['scheme'])
                : null;
            $host = is_array($parts) && is_string($parts['host'] ?? null)
                ? trim($parts['host'])
                : null;

            if (! in_array($scheme, ['http', 'https'], true)
                || ! is_string($host)
                || $host === ''
            ) {
                throw new InvalidArgumentException(
                    'Virtual Scheduling location URLs must be absolute HTTP or HTTPS URLs.',
                );
            }
        }

        $normalized = [];

        foreach (array_keys($details) as $field) {
            $value = match ($field) {
                'label' => self::optionalString($details, 'label', 255),
                'url' => $url,
                'instructions' => self::optionalString($details, 'instructions', 5000),
                'provider' => self::optionalString($details, 'provider', 80),
                default => null,
            };

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function physicalDetails(array $details): array
    {
        self::assertAllowedFields(
            $details,
            ['label', 'instructions', 'address'],
            'Physical Scheduling location details',
        );

        $address = $details['address'] ?? null;

        if (! is_array($address)) {
            throw new InvalidArgumentException(
                'Physical Scheduling location details require an address snapshot.',
            );
        }

        return array_filter([
            'label' => self::optionalString($details, 'label', 255),
            'instructions' => self::optionalString($details, 'instructions', 5000),
            'address' => self::normalizedAddress($address),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, string|float>
     */
    private static function normalizedAddress(array $address): array
    {
        self::assertAllowedFields(
            $address,
            self::ADDRESS_FIELDS,
            'Scheduling address snapshot',
        );

        $latitude = self::optionalFloat($address, 'latitude');
        $longitude = self::optionalFloat($address, 'longitude');

        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException(
                'Scheduling address latitude and longitude must either both be present or both be null.',
            );
        }

        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            throw new InvalidArgumentException(
                'Scheduling address latitude must be between -90 and 90.',
            );
        }

        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            throw new InvalidArgumentException(
                'Scheduling address longitude must be between -180 and 180.',
            );
        }

        $confidence = self::optionalFloat($address, 'confidence');

        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException(
                'Scheduling address confidence must be between 0 and 1.',
            );
        }

        return array_filter([
            'address_line_1' => self::requiredAddressString($address, 'address_line_1', 255),
            'address_line_2' => self::optionalString($address, 'address_line_2', 255),
            'city' => self::requiredAddressString($address, 'city', 255),
            'region' => self::requiredAddressString($address, 'region', 255),
            'postal_code' => self::requiredAddressString($address, 'postal_code', 40),
            'country' => self::country($address),
            'formatted_address' => self::requiredAddressString($address, 'formatted_address', 255),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => self::timezone($address),
            'precision' => self::optionalString($address, 'precision', 80),
            'confidence' => $confidence,
            'provider' => self::optionalString($address, 'provider', 80),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $allowed
     */
    private static function assertAllowedFields(
        array $values,
        array $allowed,
        string $label,
    ): void {
        $unknown = array_values(array_diff(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($values)),
            $allowed,
        ));

        if ($unknown === []) {
            return;
        }

        sort($unknown);

        throw new InvalidArgumentException(sprintf(
            '%s contain unsupported field(s): [%s].',
            $label,
            implode(', ', $unknown),
        ));
    }

    private static function requiredType(string $type): string
    {
        $type = trim($type);

        if ($type === '' || mb_strlen($type) > 80) {
            throw new InvalidArgumentException(
                'Scheduling location type must be a non-empty value no longer than 80 characters.',
            );
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function requiredAddressString(
        array $values,
        string $field,
        int $maximumLength,
    ): string {
        $value = $values[$field] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Scheduling address field [{$field}] is required.",
            );
        }

        $value = self::nullableString($value, $field, $maximumLength);

        if ($value === null) {
            throw new InvalidArgumentException(
                "Scheduling address field [{$field}] is required.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function optionalString(
        array $values,
        string $field,
        int $maximumLength,
    ): ?string {
        if (! array_key_exists($field, $values) || $values[$field] === null) {
            return null;
        }

        if (! is_string($values[$field])) {
            throw new InvalidArgumentException(
                "Scheduling location field [{$field}] must be a string or null.",
            );
        }

        return self::nullableString($values[$field], $field, $maximumLength);
    }

    private static function nullableString(
        ?string $value,
        string $field,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                "Scheduling location field [{$field}] contains unsupported control characters.",
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized)) {
            throw new InvalidArgumentException(
                "Scheduling location field [{$field}] contains invalid text.",
            );
        }

        if (mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "Scheduling location field [{$field}] cannot exceed {$maximumLength} characters.",
            );
        }

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function optionalFloat(
        array $values,
        string $field,
    ): ?float {
        if (! array_key_exists($field, $values) || $values[$field] === null) {
            return null;
        }

        if (! is_numeric($values[$field])) {
            throw new InvalidArgumentException(
                "Scheduling location field [{$field}] must be numeric or null.",
            );
        }

        return (float) $values[$field];
    }

    /**
     * @param array<string, mixed> $address
     */
    private static function country(array $address): string
    {
        $country = self::requiredAddressString($address, 'country', 255);

        if (preg_match('/^[A-Za-z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException(
                'Scheduling address field [country] must be a two-letter country code.',
            );
        }

        return strtoupper($country);
    }

    /**
     * @param array<string, mixed> $address
     */
    private static function timezone(array $address): ?string
    {
        $timezone = self::optionalString($address, 'timezone', 255);

        if ($timezone !== null
            && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)
        ) {
            throw new InvalidArgumentException(
                "Scheduling address timezone [{$timezone}] is invalid.",
            );
        }

        return $timezone;
    }
}