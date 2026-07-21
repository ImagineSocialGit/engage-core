<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\AppointmentOccupancyResolver;
use App\Modules\Scheduling\Services\Availability\AvailabilityRuleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class FindBookableAvailabilityAction
{
    public function __construct(
        private readonly AvailabilityRuleResolver $rules,
        private readonly AppointmentOccupancyResolver $occupancy,
    ) {}

    /**
     * @return array<int, BookableSlot>
     */
    public function handle(AvailabilitySearch $search): array
    {
        $service = $search->service;

        if (! $service->exists
            || $service->trashed()
            || $service->status !== BookableService::STATUS_ACTIVE
            || ! $search->hasEffectiveRange()
        ) {
            return [];
        }

        $targets = $this->targets($search);
        $slots = [];

        foreach ($targets as [$host, $assignment]) {
            foreach ($this->slotsForTarget($search, $host, $assignment) as $slot) {
                $slots[$this->slotKey($slot)] = $slot;
            }
        }

        $slots = array_values($slots);

        usort(
            $slots,
            static fn (BookableSlot $left, BookableSlot $right): int =>
                $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp()
                ?: ($left->schedulingHostId ?? 0) <=> ($right->schedulingHostId ?? 0)
                ?: $left->endsAt->getTimestamp() <=> $right->endsAt->getTimestamp(),
        );

        return $slots;
    }

    /**
     * @return array<int, array{0: SchedulingHost|null, 1: BookableServiceHost|null}>
     */
    private function targets(AvailabilitySearch $search): array
    {
        $serviceId = (int) $search->service->getKey();

        if ($search->host !== null) {
            if ($search->host->trashed()
                || $search->host->status !== SchedulingHost::STATUS_ACTIVE
            ) {
                return [];
            }

            $assignment = BookableServiceHost::query()
                ->where('bookable_service_id', $serviceId)
                ->where('scheduling_host_id', $search->host->getKey())
                ->where('is_active', true)
                ->first();

            return $assignment instanceof BookableServiceHost
                ? [[$search->host, $assignment]]
                : [];
        }

        $hasAssignments = BookableServiceHost::query()
            ->where('bookable_service_id', $serviceId)
            ->exists();

        $assignments = BookableServiceHost::query()
            ->with('schedulingHost')
            ->where('bookable_service_id', $serviceId)
            ->where('is_active', true)
            ->whereHas('schedulingHost', function ($query): void {
                $query->where('status', SchedulingHost::STATUS_ACTIVE);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($assignments->isNotEmpty()) {
            return $assignments
                ->map(static fn (BookableServiceHost $assignment): array => [
                    $assignment->schedulingHost,
                    $assignment,
                ])
                ->all();
        }

        return $hasAssignments ? [] : [[null, null]];
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function slotsForTarget(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
    ): array {
        $intervals = $this->rules->resolve($search, $host);

        if ($intervals === []) {
            return [];
        }

        $appointments = $this->occupancy->blockingAppointments($search, $host);
        $slots = [];

        foreach ($this->continuousRuns($intervals) as $run) {
            $runStartsAt = $run[0]->startsAt;
            $runEndsAt = $run[array_key_last($run)]->endsAt;
            $slotStartsAt = $this->alignUp(
                instant: $runStartsAt,
                intervalMinutes: $search->slotIntervalMinutes(),
                timezone: $search->serviceTimezone(),
            );

            while ($slotStartsAt->lessThan($runEndsAt)) {
                $slotEndsAt = $slotStartsAt->addMinutes($search->durationMinutes());

                if ($slotEndsAt->greaterThan($runEndsAt)) {
                    break;
                }

                $coverage = $this->coverage($run, $slotStartsAt, $slotEndsAt);

                if ($coverage !== null) {
                    $capacity = $this->effectiveCapacity(
                        service: $search->service,
                        host: $host,
                        assignment: $assignment,
                        availability: $coverage,
                    );

                    $remainingCapacity = $this->occupancy->remainingCapacity(
                        service: $search->service,
                        host: $host,
                        assignment: $assignment,
                        availability: $coverage,
                        startsAt: $slotStartsAt,
                        endsAt: $slotEndsAt,
                        appointments: $appointments,
                    );

                    if ($remainingCapacity > 0) {
                        $slots[] = new BookableSlot(
                            bookableServiceId: (int) $search->service->getKey(),
                            schedulingHostId: $host?->getKey(),
                            startsAt: $slotStartsAt,
                            endsAt: $slotEndsAt,
                            displayTimezone: $search->displayTimezone,
                            capacity: $capacity,
                            remainingCapacity: min($capacity, $remainingCapacity),
                            sourceScopes: $coverage->sourceScopes,
                            sourceWindowIds: $coverage->sourceWindowIds,
                        );
                    }
                }

                $slotStartsAt = $slotStartsAt->addMinutes(
                    $search->slotIntervalMinutes(),
                );
            }
        }

        return $slots;
    }

    /**
     * @param array<int, AvailabilityInterval> $intervals
     * @return array<int, array<int, AvailabilityInterval>>
     */
    private function continuousRuns(array $intervals): array
    {
        usort(
            $intervals,
            static fn (AvailabilityInterval $left, AvailabilityInterval $right): int =>
                $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp(),
        );

        $runs = [];

        foreach ($intervals as $interval) {
            $lastRunIndex = array_key_last($runs);

            if ($lastRunIndex === null) {
                $runs[] = [$interval];

                continue;
            }

            $lastInterval = $runs[$lastRunIndex][array_key_last($runs[$lastRunIndex])];

            if (! $lastInterval->endsAt->equalTo($interval->startsAt)) {
                $runs[] = [$interval];

                continue;
            }

            $runs[$lastRunIndex][] = $interval;
        }

        return $runs;
    }

    /**
     * @param array<int, AvailabilityInterval> $run
     */
    private function coverage(
        array $run,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): ?AvailabilityInterval {
        $cursor = $startsAt;
        $covering = [];

        foreach ($run as $interval) {
            if ($interval->endsAt->lessThanOrEqualTo($cursor)) {
                continue;
            }

            if ($interval->startsAt->greaterThan($cursor)) {
                return null;
            }

            if (! $interval->overlaps($startsAt, $endsAt)) {
                continue;
            }

            $covering[] = $interval;
            $cursor = $interval->endsAt->lessThan($endsAt)
                ? $interval->endsAt
                : $endsAt;

            if ($cursor->greaterThanOrEqualTo($endsAt)) {
                break;
            }
        }

        if ($cursor->lessThan($endsAt) || $covering === []) {
            return null;
        }

        $capacities = array_values(array_filter(array_map(
            static fn (AvailabilityInterval $interval): ?int => $interval->capacity,
            $covering,
        ), static fn (?int $capacity): bool => $capacity !== null));

        return new AvailabilityInterval(
            startsAt: $startsAt,
            endsAt: $endsAt,
            hostId: $covering[0]->hostId,
            capacity: $capacities === [] ? null : min($capacities),
            sourceScopes: array_merge(...array_map(
                static fn (AvailabilityInterval $interval): array => $interval->sourceScopes,
                $covering,
            )),
            sourceWindowIds: array_merge(...array_map(
                static fn (AvailabilityInterval $interval): array => $interval->sourceWindowIds,
                $covering,
            )),
            sourceTimezones: array_merge(...array_map(
                static fn (AvailabilityInterval $interval): array => $interval->sourceTimezones,
                $covering,
            )),
        );
    }

    private function effectiveCapacity(
        BookableService $service,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
        AvailabilityInterval $availability,
    ): int {
        $capacities = [max(1, (int) $service->capacity)];

        if ($host !== null) {
            $capacities[] = max(1, (int) $host->capacity);
        }

        if ($assignment?->capacity_override !== null) {
            $capacities[] = max(1, (int) $assignment->capacity_override);
        }

        if ($availability->capacity !== null) {
            $capacities[] = max(1, $availability->capacity);
        }

        return min($capacities);
    }

    private function alignUp(
        CarbonImmutable $instant,
        int $intervalMinutes,
        string $timezone,
    ): CarbonImmutable {
        $local = $instant->setTimezone($timezone);
        $stepSeconds = max(1, $intervalMinutes) * 60;
        $secondsOfDay = ($local->hour * 3600)
            + ($local->minute * 60)
            + $local->second;
        $alignedSeconds = (int) (ceil($secondsOfDay / $stepSeconds) * $stepSeconds);

        for ($attempt = 0; $attempt < 2000; $attempt++, $alignedSeconds += $stepSeconds) {
            $dayOffset = intdiv($alignedSeconds, 86400);
            $timeSeconds = $alignedSeconds % 86400;
            $date = $local->startOfDay()->addDays($dayOffset);
            $normalized = sprintf(
                '%s %02d:%02d:%02d',
                $date->format('Y-m-d'),
                intdiv($timeSeconds, 3600),
                intdiv($timeSeconds % 3600, 60),
                $timeSeconds % 60,
            );

            try {
                $candidate = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $normalized,
                    $timezone,
                );
            } catch (Throwable) {
                continue;
            }

            if (! $candidate instanceof CarbonImmutable
                || $candidate->format('Y-m-d H:i:s') !== $normalized
            ) {
                continue;
            }

            $candidate = $candidate->utc();

            if ($candidate->greaterThanOrEqualTo($instant)) {
                return $candidate;
            }
        }

        return $instant;
    }

    private function slotKey(BookableSlot $slot): string
    {
        return implode(':', [
            $slot->bookableServiceId,
            $slot->schedulingHostId ?? 'unhosted',
            $slot->startsAt->getTimestamp(),
            $slot->endsAt->getTimestamp(),
        ]);
    }
}