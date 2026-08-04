<?php

namespace App\Modules\Location\Data;

use InvalidArgumentException;

final readonly class LocationNormalizationInput
{
    public const FIELDS = [
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
    ];

    public string $addressLine1;
    public ?string $addressLine2;
    public string $city;
    public string $region;
    public string $postalCode;
    public string $country;

    public function __construct(
        string $addressLine1,
        ?string $addressLine2,
        string $city,
        string $region,
        string $postalCode,
        string $country,
    ) {
        $this->addressLine1 = self::requiredString($addressLine1, 'address_line_1', 255);
        $this->addressLine2 = self::nullableString($addressLine2, 'address_line_2', 255);
        $this->city = self::requiredString($city, 'city', 255);
        $this->region = self::requiredString($region, 'region', 255);
        $this->postalCode = self::requiredString($postalCode, 'postal_code', 40);
        $this->country = self::country($country);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $unknownFields = array_values(array_diff(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($input)),
            self::FIELDS,
        ));

        if ($unknownFields !== []) {
            sort($unknownFields);

            throw new InvalidArgumentException(sprintf(
                'Unsupported location normalization input field(s): [%s].',
                implode(', ', $unknownFields),
            ));
        }

        return new self(
            addressLine1: self::arrayString($input, 'address_line_1'),
            addressLine2: self::arrayNullableString($input, 'address_line_2'),
            city: self::arrayString($input, 'city'),
            region: self::arrayString($input, 'region'),
            postalCode: self::arrayString($input, 'postal_code'),
            country: self::arrayString($input, 'country'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function arrayString(array $input, string $field): string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] is required.",
            );
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] must be a string.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function arrayNullableString(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] must be a string or null.",
            );
        }

        return $value;
    }

    private static function requiredString(
        string $value,
        string $field,
        int $maximumLength,
    ): string {
        $value = self::normalizedString($value, $field, $maximumLength);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] is required.",
            );
        }

        return $value;
    }

    private static function nullableString(
        ?string $value,
        string $field,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = self::normalizedString($value, $field, $maximumLength);

        return $value !== '' ? $value : null;
    }

    private static function normalizedString(
        string $value,
        string $field,
        int $maximumLength,
    ): string {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] contains unsupported control characters.",
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized)) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] contains invalid text.",
            );
        }

        if (mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "Location normalization field [{$field}] cannot exceed {$maximumLength} characters.",
            );
        }

        return $normalized;
    }

    private static function country(string $value): string
    {
        $country = self::requiredString($value, 'country', 255);

        if (preg_match('/^[A-Za-z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException(
                'Location normalization field [country] must be a two-letter country code.',
            );
        }

        return strtoupper($country);
    }
}