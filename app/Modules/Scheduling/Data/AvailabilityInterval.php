<?php

namespace App\Modules\Scheduling\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class AvailabilityInterval
{
    /**
     * @param array<int, string> $sourceScopes
     * @param array<int, int> $sourceWindowIds
     * @param array<int, string> $sourceTimezones
     */
    public function __construct(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $hostId = null,
        ?int $capacity = null,
        array $sourceScopes = [],
        array $sourceWindowIds = [],
        array $sourceTimezones = [],
    ) {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw new InvalidArgumentException(
                'Availability intervals require startsAt before endsAt.',
            );
        }

        if ($capacity !== null && $capacity < 1) {
            throw new InvalidArgumentException(
                'Availability interval capacity must be at least 1 when provided.',
            );
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->hostId = $hostId;
        $this->capacity = $capacity;
        $this->sourceScopes = $this->normalizedStrings($sourceScopes);
        $this->sourceWindowIds = $this->normalizedIntegers($sourceWindowIds);
        $this->sourceTimezones = $this->normalizedStrings($sourceTimezones);
    }

    public CarbonImmutable $startsAt;
    public CarbonImmutable $endsAt;
    public ?int $hostId;
    public ?int $capacity;
    public array $sourceScopes;
    public array $sourceWindowIds;
    public array $sourceTimezones;

    public function overlaps(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();

        return $this->startsAt->lessThan($endsAt)
            && $this->endsAt->greaterThan($startsAt);
    }

    public function contains(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();

        return $this->startsAt->lessThanOrEqualTo($startsAt)
            && $this->endsAt->greaterThanOrEqualTo($endsAt);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function normalizedStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));

        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<int, int> $values
     * @return array<int, int>
     */
    private function normalizedIntegers(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?int => is_numeric($value) && (int) $value > 0
                ? (int) $value
                : null,
            $values,
        ))));

        sort($values, SORT_NUMERIC);

        return $values;
    }
}