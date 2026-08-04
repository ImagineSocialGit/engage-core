<?php

namespace App\Modules\Location\Data;

use DateTimeZone;
use InvalidArgumentException;

final readonly class NormalizedLocationData
{
    public string $addressLine1;
    public ?string $addressLine2;
    public string $city;
    public string $region;
    public string $postalCode;
    public string $country;
    public string $formattedAddress;
    public ?float $latitude;
    public ?float $longitude;
    public ?string $timezone;
    public ?string $precision;
    public ?float $confidence;
    public ?string $provider;

    public function __construct(
        string $addressLine1,
        ?string $addressLine2,
        string $city,
        string $region,
        string $postalCode,
        string $country,
        string $formattedAddress,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $timezone = null,
        ?string $precision = null,
        ?float $confidence = null,
        ?string $provider = null,
    ) {
        $this->addressLine1 = self::requiredString($addressLine1, 'address_line_1', 255);
        $this->addressLine2 = self::nullableString($addressLine2, 'address_line_2', 255);
        $this->city = self::requiredString($city, 'city', 255);
        $this->region = self::requiredString($region, 'region', 255);
        $this->postalCode = self::requiredString($postalCode, 'postal_code', 40);
        $this->country = self::country($country);
        $this->formattedAddress = self::requiredString($formattedAddress, 'formatted_address', 255);

        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException(
                'Normalized location latitude and longitude must either both be present or both be null.',
            );
        }

        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            throw new InvalidArgumentException(
                'Normalized location latitude must be between -90 and 90.',
            );
        }

        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            throw new InvalidArgumentException(
                'Normalized location longitude must be between -180 and 180.',
            );
        }

        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->timezone = self::timezone($timezone);
        $this->precision = self::nullableString($precision, 'precision', 80);

        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException(
                'Normalized location confidence must be between 0 and 1.',
            );
        }

        $this->confidence = $confidence;
        $this->provider = self::nullableString($provider, 'provider', 80);
    }

    public static function fromInput(
        LocationNormalizationInput $input,
        string $formattedAddress,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $timezone = null,
        ?string $precision = null,
        ?float $confidence = null,
        ?string $provider = null,
    ): self {
        return new self(
            addressLine1: $input->addressLine1,
            addressLine2: $input->addressLine2,
            city: $input->city,
            region: $input->region,
            postalCode: $input->postalCode,
            country: $input->country,
            formattedAddress: $formattedAddress,
            latitude: $latitude,
            longitude: $longitude,
            timezone: $timezone,
            precision: $precision,
            confidence: $confidence,
            provider: $provider,
        );
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * @return array<string, string|float|null>
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
            'formatted_address' => $this->formattedAddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'precision' => $this->precision,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
        ];
    }

    private static function requiredString(
        string $value,
        string $field,
        int $maximumLength,
    ): string {
        $value = self::normalizedString($value, $field, $maximumLength);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Normalized location field [{$field}] is required.",
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
                "Normalized location field [{$field}] contains unsupported control characters.",
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized)) {
            throw new InvalidArgumentException(
                "Normalized location field [{$field}] contains invalid text.",
            );
        }

        if (mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "Normalized location field [{$field}] cannot exceed {$maximumLength} characters.",
            );
        }

        return $normalized;
    }

    private static function country(string $value): string
    {
        $country = self::requiredString($value, 'country', 255);

        if (preg_match('/^[A-Za-z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException(
                'Normalized location field [country] must be a two-letter country code.',
            );
        }

        return strtoupper($country);
    }

    private static function timezone(?string $value): ?string
    {
        $timezone = self::nullableString($value, 'timezone', 255);

        if ($timezone !== null
            && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)
        ) {
            throw new InvalidArgumentException(
                "Normalized location timezone [{$timezone}] is invalid.",
            );
        }

        return $timezone;
    }
}