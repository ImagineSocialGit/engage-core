<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AvailabilityRuleResolver
{
    private const SCOPE_SERVICE = 'service';
    private const SCOPE_HOST = 'host';
    private const SCOPE_SERVICE_HOST = 'service_host';

    /**
     * @return array<int, AvailabilityInterval>
     */
    public function resolve(
        AvailabilitySearch $search,
        ?SchedulingHost $host = null,
    ): array {
        if (! $search->hasEffectiveRange()) {
            return [];
        }

        $rules = $this->rules($search, $host);
        $availableByScope = [];
        $positiveScopes = [];
        $blackouts = [];

        foreach ($rules as $rule) {
            $scope = $this->scope($rule);
            $expanded = $this->expand($rule, $search, $host);

            if ($rule->is_available) {
                $positiveScopes[$scope] = true;

                foreach ($expanded as $interval) {
                    $availableByScope[$scope][] = $interval;
                }

                continue;
            }

            foreach ($expanded as $interval) {
                $blackouts[] = $interval;
            }
        }

        $layers = [];

        foreach ($this->scopeOrder($host) as $scope) {
            if (! ($positiveScopes[$scope] ?? false)) {
                continue;
            }

            $layer = $this->segmentUnion($availableByScope[$scope] ?? []);

            if ($layer === []) {
                return [];
            }

            $layers[] = $layer;
        }

        if ($layers === []) {
            return [];
        }

        $resolved = array_shift($layers);

        foreach ($layers as $layer) {
            $resolved = $this->intersect($resolved, $layer);

            if ($resolved === []) {
                return [];
            }
        }

        if ($blackouts !== []) {
            $resolved = $this->subtract(
                $resolved,
                $this->segmentUnion($blackouts),
            );
        }

        return $this->mergeAdjacent($resolved);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow>
     */
    private function rules(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): \Illuminate\Database\Eloquent\Collection {
        $serviceId = (int) $search->service->getKey();
        $hostId = $host?->getKey();

        return SchedulingAvailabilityWindow::query()
            ->where(function (Builder $query) use ($serviceId, $hostId): void {
                $query->where(function (Builder $serviceWide) use ($serviceId): void {
                    $serviceWide
                        ->where('bookable_service_id', $serviceId)
                        ->whereNull('scheduling_host_id');
                });

                if ($hostId === null) {
                    return;
                }

                $query
                    ->orWhere(function (Builder $hostWide) use ($hostId): void {
                        $hostWide
                            ->whereNull('bookable_service_id')
                            ->where('scheduling_host_id', $hostId);
                    })
                    ->orWhere(function (Builder $serviceHost) use ($serviceId, $hostId): void {
                        $serviceHost
                            ->where('bookable_service_id', $serviceId)
                            ->where('scheduling_host_id', $hostId);
                    });
            })
            ->orderBy('id')
            ->get();
    }

    private function scope(SchedulingAvailabilityWindow $rule): string
    {
        if ($rule->bookable_service_id !== null && $rule->scheduling_host_id !== null) {
            return self::SCOPE_SERVICE_HOST;
        }

        if ($rule->scheduling_host_id !== null) {
            return self::SCOPE_HOST;
        }

        return self::SCOPE_SERVICE;
    }

    /**
     * @return array<int, string>
     */
    private function scopeOrder(?SchedulingHost $host): array
    {
        return $host === null
            ? [self::SCOPE_SERVICE]
            : [self::SCOPE_SERVICE, self::SCOPE_HOST, self::SCOPE_SERVICE_HOST];
    }

    /**
     * @return array<int, AvailabilityInterval>
     */
    private function expand(
        SchedulingAvailabilityWindow $rule,
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): array {
        return match ($rule->window_type) {
            SchedulingAvailabilityWindowType::Weekly => $this->expandWeekly($rule, $search, $host),
            SchedulingAvailabilityWindowType::Absolute => $this->expandAbsolute($rule, $search, $host),
        };
    }

    /**
     * @return array<int, AvailabilityInterval>
     */
    private function expandWeekly(
        SchedulingAvailabilityWindow $rule,
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): array {
        $timezone = (string) $rule->timezone;
        $firstDate = $search->effectiveStartsAt->setTimezone($timezone)->startOfDay();
        $lastDate = $search->effectiveEndsAt->setTimezone($timezone)->startOfDay();
        $intervals = [];

        for ($date = $firstDate; $date->lessThanOrEqualTo($lastDate); $date = $date->addDay()) {
            if ($date->dayOfWeek !== (int) $rule->weekday) {
                continue;
            }

            $startsAt = $this->localDateTime($date, (string) $rule->start_time, $timezone);
            $endsAt = $this->localDateTime($date, (string) $rule->end_time, $timezone);

            if ($startsAt === null || $endsAt === null || $startsAt->greaterThanOrEqualTo($endsAt)) {
                continue;
            }

            $interval = $this->clippedInterval(
                startsAt: $startsAt->utc(),
                endsAt: $endsAt->utc(),
                rule: $rule,
                search: $search,
                host: $host,
            );

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $intervals;
    }

    /**
     * @return array<int, AvailabilityInterval>
     */
    private function expandAbsolute(
        SchedulingAvailabilityWindow $rule,
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): array {
        if ($rule->starts_at === null || $rule->ends_at === null) {
            return [];
        }

        $interval = $this->clippedInterval(
            startsAt: CarbonImmutable::instance($rule->starts_at)->utc(),
            endsAt: CarbonImmutable::instance($rule->ends_at)->utc(),
            rule: $rule,
            search: $search,
            host: $host,
        );

        return $interval !== null ? [$interval] : [];
    }

    private function clippedInterval(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        SchedulingAvailabilityWindow $rule,
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): ?AvailabilityInterval {
        $startsAt = $startsAt->greaterThan($search->effectiveStartsAt)
            ? $startsAt
            : $search->effectiveStartsAt;
        $endsAt = $endsAt->lessThan($search->effectiveEndsAt)
            ? $endsAt
            : $search->effectiveEndsAt;

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            return null;
        }

        return new AvailabilityInterval(
            startsAt: $startsAt,
            endsAt: $endsAt,
            hostId: $host?->getKey(),
            capacity: $rule->capacity !== null ? (int) $rule->capacity : null,
            sourceScopes: [$this->scope($rule)],
            sourceWindowIds: [(int) $rule->getKey()],
            sourceTimezones: [(string) $rule->timezone],
        );
    }

    private function localDateTime(
        CarbonImmutable $date,
        string $time,
        string $timezone,
    ): ?CarbonImmutable {
        $time = trim($time);

        if (preg_match('/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/', $time, $matches) !== 1) {
            return null;
        }

        $normalized = sprintf(
            '%s %02d:%02d:%02d',
            $date->format('Y-m-d'),
            (int) $matches['hour'],
            (int) $matches['minute'],
            (int) ($matches['second'] ?? 0),
        );

        try {
            $value = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $normalized,
                $timezone,
            );
        } catch (Throwable) {
            return null;
        }

        if (! $value instanceof CarbonImmutable || $value->format('Y-m-d H:i:s') !== $normalized) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     * @return array<int, AvailabilityInterval>
     */
    private function segmentUnion(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        $boundaries = [];

        foreach ($intervals as $interval) {
            $boundaries[] = $interval->startsAt->getTimestamp();
            $boundaries[] = $interval->endsAt->getTimestamp();
        }

        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries, SORT_NUMERIC);
        $segments = [];

        for ($index = 0, $last = count($boundaries) - 1; $index < $last; $index++) {
            $startTimestamp = $boundaries[$index];
            $endTimestamp = $boundaries[$index + 1];

            if ($startTimestamp >= $endTimestamp) {
                continue;
            }

            $covering = array_values(array_filter(
                $intervals,
                static fn (AvailabilityInterval $interval): bool =>
                    $interval->startsAt->getTimestamp() <= $startTimestamp
                    && $interval->endsAt->getTimestamp() >= $endTimestamp,
            ));

            if ($covering === []) {
                continue;
            }

            $segments[] = new AvailabilityInterval(
                startsAt: CarbonImmutable::createFromTimestampUTC($startTimestamp),
                endsAt: CarbonImmutable::createFromTimestampUTC($endTimestamp),
                hostId: $covering[0]->hostId,
                capacity: $this->minimumCapacity($covering),
                sourceScopes: $this->mergedStrings($covering, 'sourceScopes'),
                sourceWindowIds: $this->mergedIntegers($covering, 'sourceWindowIds'),
                sourceTimezones: $this->mergedStrings($covering, 'sourceTimezones'),
            );
        }

        return $this->mergeAdjacent($segments);
    }

    /**
     * @param array<int, AvailabilityInterval> $left
     * @param array<int, AvailabilityInterval> $right
     * @return array<int, AvailabilityInterval>
     */
    private function intersect(array $left, array $right): array
    {
        $intersections = [];
        $leftIndex = 0;
        $rightIndex = 0;

        while (isset($left[$leftIndex], $right[$rightIndex])) {
            $leftInterval = $left[$leftIndex];
            $rightInterval = $right[$rightIndex];
            $startsAt = $leftInterval->startsAt->greaterThan($rightInterval->startsAt)
                ? $leftInterval->startsAt
                : $rightInterval->startsAt;
            $endsAt = $leftInterval->endsAt->lessThan($rightInterval->endsAt)
                ? $leftInterval->endsAt
                : $rightInterval->endsAt;

            if ($startsAt->lessThan($endsAt)) {
                $intersections[] = new AvailabilityInterval(
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    hostId: $leftInterval->hostId ?? $rightInterval->hostId,
                    capacity: $this->minimumCapacity([$leftInterval, $rightInterval]),
                    sourceScopes: [...$leftInterval->sourceScopes, ...$rightInterval->sourceScopes],
                    sourceWindowIds: [...$leftInterval->sourceWindowIds, ...$rightInterval->sourceWindowIds],
                    sourceTimezones: [...$leftInterval->sourceTimezones, ...$rightInterval->sourceTimezones],
                );
            }

            if ($leftInterval->endsAt->lessThanOrEqualTo($rightInterval->endsAt)) {
                $leftIndex++;
            } else {
                $rightIndex++;
            }
        }

        return $this->mergeAdjacent($intersections);
    }

    /**
     * @param array<int, AvailabilityInterval> $available
     * @param array<int, AvailabilityInterval> $blackouts
     * @return array<int, AvailabilityInterval>
     */
    private function subtract(array $available, array $blackouts): array
    {
        $resolved = $available;

        foreach ($blackouts as $blackout) {
            $next = [];

            foreach ($resolved as $interval) {
                if (! $interval->overlaps($blackout->startsAt, $blackout->endsAt)) {
                    $next[] = $interval;

                    continue;
                }

                if ($blackout->startsAt->greaterThan($interval->startsAt)) {
                    $next[] = new AvailabilityInterval(
                        startsAt: $interval->startsAt,
                        endsAt: $blackout->startsAt->lessThan($interval->endsAt)
                            ? $blackout->startsAt
                            : $interval->endsAt,
                        hostId: $interval->hostId,
                        capacity: $interval->capacity,
                        sourceScopes: $interval->sourceScopes,
                        sourceWindowIds: $interval->sourceWindowIds,
                        sourceTimezones: $interval->sourceTimezones,
                    );
                }

                if ($blackout->endsAt->lessThan($interval->endsAt)) {
                    $next[] = new AvailabilityInterval(
                        startsAt: $blackout->endsAt->greaterThan($interval->startsAt)
                            ? $blackout->endsAt
                            : $interval->startsAt,
                        endsAt: $interval->endsAt,
                        hostId: $interval->hostId,
                        capacity: $interval->capacity,
                        sourceScopes: $interval->sourceScopes,
                        sourceWindowIds: $interval->sourceWindowIds,
                        sourceTimezones: $interval->sourceTimezones,
                    );
                }
            }

            $resolved = $next;

            if ($resolved === []) {
                break;
            }
        }

        return $this->mergeAdjacent($resolved);
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     * @return array<int, AvailabilityInterval>
     */
    private function mergeAdjacent(array $intervals): array
    {
        usort(
            $intervals,
            static fn (AvailabilityInterval $left, AvailabilityInterval $right): int =>
                $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp()
                ?: $left->endsAt->getTimestamp() <=> $right->endsAt->getTimestamp(),
        );

        $merged = [];

        foreach ($intervals as $interval) {
            $previous = $merged[array_key_last($merged)] ?? null;

            if (! $previous instanceof AvailabilityInterval
                || ! $previous->endsAt->equalTo($interval->startsAt)
                || $previous->hostId !== $interval->hostId
                || $previous->capacity !== $interval->capacity
                || $previous->sourceScopes !== $interval->sourceScopes
                || $previous->sourceWindowIds !== $interval->sourceWindowIds
                || $previous->sourceTimezones !== $interval->sourceTimezones
            ) {
                $merged[] = $interval;

                continue;
            }

            array_pop($merged);
            $merged[] = new AvailabilityInterval(
                startsAt: $previous->startsAt,
                endsAt: $interval->endsAt,
                hostId: $previous->hostId,
                capacity: $previous->capacity,
                sourceScopes: $previous->sourceScopes,
                sourceWindowIds: $previous->sourceWindowIds,
                sourceTimezones: $previous->sourceTimezones,
            );
        }

        return array_values($merged);
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     */
    private function minimumCapacity(array $intervals): ?int
    {
        $capacities = array_values(array_filter(array_map(
            static fn (AvailabilityInterval $interval): ?int => $interval->capacity,
            $intervals,
        ), static fn (?int $capacity): bool => $capacity !== null));

        return $capacities === [] ? null : min($capacities);
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     * @return array<int, string>
     */
    private function mergedStrings(array $intervals, string $property): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn (AvailabilityInterval $interval): array => $interval->{$property},
            $intervals,
        ))));
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     * @return array<int, int>
     */
    private function mergedIntegers(array $intervals, string $property): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn (AvailabilityInterval $interval): array => $interval->{$property},
            $intervals,
        ))));
    }
}