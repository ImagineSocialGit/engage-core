<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use DomainException;
use InvalidArgumentException;

final class SchedulingLocationSnapshotResolver
{
    private const ADDRESS_INPUT_FIELDS = [
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function normalizeAddress(
        string $type,
        array $input,
        ?string $label = null,
        ?string $instructions = null,
    ): SchedulingLocationSnapshot {
        if (! in_array($type, [
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
        ], true)) {
            throw new InvalidArgumentException(
                'Scheduling address normalization requires fixed or customer_site location type.',
            );
        }

        return SchedulingLocationSnapshot::fromNormalizedAddress(
            type: $type,
            address: $this->normalizedAddress($input),
            label: $label,
            instructions: $instructions,
        );
    }

    public function forCommitment(
        BookableService $service,
        ?SchedulingLocationSnapshot $requested = null,
        ?Appointment $rescheduleSource = null,
    ): ?SchedulingLocationSnapshot {
        $type = $this->nullableString($service->location_type);
        $details = is_array($service->location_details)
            ? $service->location_details
            : null;

        if ($type === BookableService::LOCATION_TYPE_CUSTOMER_SITE) {
            if ($requested instanceof SchedulingLocationSnapshot) {
                if (! $requested->canonical || ! $requested->isCustomerSite()) {
                    throw new DomainException(
                        'Customer-site services require a canonical customer_site location snapshot.',
                    );
                }

                return $this->withCustomerSitePolicyDefaults(
                    requested: $requested,
                    policyDetails: $details,
                );
            }

            $historical = $rescheduleSource?->locationSnapshot();

            if ($historical instanceof SchedulingLocationSnapshot
                && $historical->isCustomerSite()
            ) {
                return $historical;
            }

            throw new DomainException(
                'Customer-site services require a normalized service address before a booking commitment can be created.',
            );
        }

        if ($requested instanceof SchedulingLocationSnapshot) {
            throw new DomainException(
                'Only customer-site services accept a booking-specific location snapshot.',
            );
        }

        if ($type === null) {
            return $rescheduleSource?->locationSnapshot();
        }

        if (in_array($type, [
            BookableService::LOCATION_TYPE_PHONE,
            BookableService::LOCATION_TYPE_VIRTUAL,
            BookableService::LOCATION_TYPE_FIXED,
        ], true)) {
            try {
                return SchedulingLocationSnapshot::canonical($type, $details);
            } catch (InvalidArgumentException $exception) {
                throw new DomainException(
                    "Bookable service [{$service->key}] has invalid canonical location configuration: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }

        return $rescheduleSource?->locationSnapshot()
            ?? SchedulingLocationSnapshot::legacy($type, $details);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function normalizedAddress(array $input): array
    {
        $unknown = array_values(array_diff(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($input)),
            self::ADDRESS_INPUT_FIELDS,
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Unsupported Scheduling address input field(s): [%s].',
                implode(', ', $unknown),
            ));
        }

        $addressLine1 = $this->requiredInputString($input, 'address_line_1', 255);
        $addressLine2 = $this->optionalInputString($input, 'address_line_2', 255);
        $city = $this->requiredInputString($input, 'city', 255);
        $region = $this->requiredInputString($input, 'region', 255);
        $postalCode = $this->requiredInputString($input, 'postal_code', 40);
        $country = $this->country($input);
        $regionAndPostalCode = trim(implode(' ', [$region, $postalCode]));

        return [
            'address_line_1' => $addressLine1,
            'address_line_2' => $addressLine2,
            'city' => $city,
            'region' => $region,
            'postal_code' => $postalCode,
            'country' => $country,
            'formatted_address' => implode(', ', array_values(array_filter([
                $addressLine1,
                $addressLine2,
                $city,
                $regionAndPostalCode,
                $country,
            ], static fn (?string $value): bool => $value !== null && $value !== ''))),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredInputString(
        array $input,
        string $field,
        int $maximumLength,
    ): string {
        $value = $input[$field] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] is required.",
            );
        }

        $value = $this->normalizedInputString($value, $field, $maximumLength);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] is required.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalInputString(
        array $input,
        string $field,
        int $maximumLength,
    ): ?string {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] must be a string or null.",
            );
        }

        $value = $this->normalizedInputString($value, $field, $maximumLength);

        return $value !== '' ? $value : null;
    }

    private function normalizedInputString(
        string $value,
        string $field,
        int $maximumLength,
    ): string {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] contains unsupported control characters.",
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized)) {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] contains invalid text.",
            );
        }

        if (mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "Scheduling address input field [{$field}] cannot exceed {$maximumLength} characters.",
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function country(array $input): string
    {
        $country = $this->requiredInputString($input, 'country', 255);

        if (preg_match('/^[A-Za-z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException(
                'Scheduling address input field [country] must be a two-letter country code.',
            );
        }

        return strtoupper($country);
    }

    /**
     * @param array<string, mixed>|null $policyDetails
     */
    private function withCustomerSitePolicyDefaults(
        SchedulingLocationSnapshot $requested,
        ?array $policyDetails,
    ): SchedulingLocationSnapshot {
        $policyDetails ??= [];
        $unknown = array_values(array_diff(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($policyDetails)),
            ['label', 'instructions'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw new DomainException(sprintf(
                'Customer-site service policy contains unsupported field(s): [%s].',
                implode(', ', $unknown),
            ));
        }

        $requestedDetails = is_array($requested->details)
            ? $requested->details
            : [];
        $address = $requestedDetails['address'] ?? null;

        if (! is_array($address)) {
            throw new DomainException(
                'Customer-site location snapshots require normalized address facts.',
            );
        }

        return SchedulingLocationSnapshot::canonical(
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            array_filter([
                'label' => $requestedDetails['label']
                    ?? $this->nullableString($policyDetails['label'] ?? null),
                'instructions' => $requestedDetails['instructions']
                    ?? $this->nullableString($policyDetails['instructions'] ?? null),
                'address' => $address,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}