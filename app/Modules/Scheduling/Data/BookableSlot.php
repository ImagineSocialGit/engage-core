<?php

namespace App\Modules\Scheduling\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class BookableSlot
{
    /**
     * @param array<int, string> $sourceScopes
     * @param array<int, int> $sourceWindowIds
     */
    public function __construct(
        int $bookableServiceId,
        ?int $schedulingHostId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $displayTimezone,
        int $capacity,
        int $remainingCapacity,
        array $sourceScopes = [],
        array $sourceWindowIds = [],
        ?int $travelMinutesBefore = null,
        ?int $travelMinutesAfter = null,
    ) {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();
        $displayTimezone = trim($displayTimezone);

        if ($bookableServiceId < 1) {
            throw new InvalidArgumentException('Bookable slots require a persisted service identity.');
        }

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw new InvalidArgumentException('Bookable slots require startsAt before endsAt.');
        }

        if ($displayTimezone === '' || ! in_array($displayTimezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(
                "Bookable slot display timezone [{$displayTimezone}] is invalid.",
            );
        }

        if ($capacity < 1 || $remainingCapacity < 1 || $remainingCapacity > $capacity) {
            throw new InvalidArgumentException(
                'Bookable slot capacity must be positive and remaining capacity cannot exceed total capacity.',
            );
        }

        $this->bookableServiceId = $bookableServiceId;
        $this->schedulingHostId = $schedulingHostId;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->displayTimezone = $displayTimezone;
        $this->capacity = $capacity;
        $this->remainingCapacity = $remainingCapacity;
        $this->sourceScopes = $this->normalizedStrings($sourceScopes);
        $this->sourceWindowIds = $this->normalizedIntegers($sourceWindowIds);
        $this->travelMinutesBefore = $this->normalizedTravelMinutes($travelMinutesBefore);
        $this->travelMinutesAfter = $this->normalizedTravelMinutes($travelMinutesAfter);
    }

    public int $bookableServiceId;
    public ?int $schedulingHostId;
    public CarbonImmutable $startsAt;
    public CarbonImmutable $endsAt;
    public string $displayTimezone;
    public int $capacity;
    public int $remainingCapacity;
    public array $sourceScopes;
    public array $sourceWindowIds;
    public ?int $travelMinutesBefore;
    public ?int $travelMinutesAfter;

    public function localStartsAt(): CarbonImmutable
    {
        return $this->startsAt->setTimezone($this->displayTimezone);
    }

    public function localEndsAt(): CarbonImmutable
    {
        return $this->endsAt->setTimezone($this->displayTimezone);
    }

    public function totalTravelMinutes(): ?int
    {
        if ($this->travelMinutesBefore === null && $this->travelMinutesAfter === null) {
            return null;
        }

        return ($this->travelMinutesBefore ?? 0) + ($this->travelMinutesAfter ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bookable_service_id' => $this->bookableServiceId,
            'scheduling_host_id' => $this->schedulingHostId,
            'starts_at' => $this->startsAt->toISOString(),
            'ends_at' => $this->endsAt->toISOString(),
            'display_timezone' => $this->displayTimezone,
            'local_starts_at' => $this->localStartsAt()->toISOString(),
            'local_ends_at' => $this->localEndsAt()->toISOString(),
            'capacity' => $this->capacity,
            'remaining_capacity' => $this->remainingCapacity,
            'source_scopes' => $this->sourceScopes,
            'source_window_ids' => $this->sourceWindowIds,
        ];
    }

    private function normalizedTravelMinutes(?int $minutes): ?int
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 0 || $minutes > 1440) {
            throw new InvalidArgumentException(
                'Bookable slot travel minutes must be between 0 and 1440.',
            );
        }

        return $minutes;
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