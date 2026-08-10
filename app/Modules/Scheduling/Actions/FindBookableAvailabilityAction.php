<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\AvailabilityRuleResolver;
use App\Modules\Scheduling\Services\Availability\BookingOccupancyResolver;
use App\Modules\Scheduling\Services\Availability\TravelFeasibilityResolver;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use Throwable;

class FindBookableAvailabilityAction
{
    public function __construct(
        private readonly AvailabilityRuleResolver $rules,
        private readonly BookingOccupancyResolver $occupancy,
        private readonly TravelFeasibilityResolver $travel,
        private readonly SchedulingLocationSnapshotResolver $locations,
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
            || ! $service->hasValidDurationPolicy()
            || ! $search->hasEffectiveRange()
        ) {
            return [];
        }

        $candidateLocation = $this->candidateLocation($search);

        if ($search->service->location_type === BookableService::LOCATION_TYPE_FIXED
            && ! $candidateLocation instanceof SchedulingLocationSnapshot
        ) {
            return [];
        }

        $targets = $this->targets($search);
        $slots = [];

        foreach ($targets as [$host, $assignment]) {
            foreach ($this->slotsForTarget(
                search: $search,
                host: $host,
                assignment: $assignment,
                candidateLocation: $candidateLocation,
            ) as $slot) {
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

    private function candidateLocation(AvailabilitySearch $search): ?SchedulingLocationSnapshot
    {
        if ($search->location instanceof SchedulingLocationSnapshot) {
            return $search->location;
        }

        if ($search->rescheduleAppointment instanceof Appointment) {
            try {
                return $search->rescheduleAppointment->locationSnapshot();
            } catch (Throwable) {
                return null;
            }
        }

        if ($search->service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE) {
            return null;
        }

        try {
            return $this->locations->forCommitment($search->service);
        } catch (Throwable) {
            return null;
        }
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
        ?SchedulingLocationSnapshot $candidateLocation,
    ): array {
        $intervals = $this->rules->resolve($search, $host);

        if ($intervals === []) {
            return [];
        }

        $appointments = $this->occupancy->blockingAppointments($search, $host);
        $holds = $this->occupancy->activeHolds($search, $host);

        if ($search->service->usesRangeDuration()) {
            return $this->rangeSlotsForTarget(
                search: $search,
                host: $host,
                assignment: $assignment,
                candidateLocation: $candidateLocation,
                intervals: $intervals,
                appointments: $appointments,
                holds: $holds,
            );
        }

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
                    $slot = $this->bookableSlot(
                        search: $search,
                        host: $host,
                        assignment: $assignment,
                        candidateLocation: $candidateLocation,
                        availability: $coverage,
                        startsAt: $slotStartsAt,
                        endsAt: $slotEndsAt,
                        appointments: $appointments,
                        holds: $holds,
                    );

                    if ($slot instanceof BookableSlot) {
                        $slots[] = $slot;
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
     * Range-duration services use availability windows as admissible check-in
     * and check-out boundaries. Capacity and resource occupancy are still
     * evaluated across the complete stay interval, including closed hours
     * between those boundaries.
     *
     * @param array<int, AvailabilityInterval> $intervals
     * @param \Illuminate\Database\Eloquent\Collection<int, Appointment> $appointments
     * @param \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Scheduling\Models\BookingHold> $holds
     * @return array<int, BookableSlot>
     */
    private function rangeSlotsForTarget(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
        ?SchedulingLocationSnapshot $candidateLocation,
        array $intervals,
        \Illuminate\Database\Eloquent\Collection $appointments,
        \Illuminate\Database\Eloquent\Collection $holds,
    ): array {
        usort(
            $intervals,
            static fn (AvailabilityInterval $left, AvailabilityInterval $right): int =>
                $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp(),
        );

        $slots = [];
        $durationMinutes = $search->durationMinutes();

        foreach ($intervals as $startInterval) {
            $slotStartsAt = $this->alignUp(
                instant: $startInterval->startsAt,
                intervalMinutes: $search->slotIntervalMinutes(),
                timezone: $search->serviceTimezone(),
            );

            while ($slotStartsAt->lessThan($startInterval->endsAt)) {
                $slotEndsAt = $slotStartsAt->addMinutes($durationMinutes);

                if ($slotEndsAt->greaterThan($search->effectiveEndsAt)) {
                    break;
                }

                $endInterval = $this->rangeEndBoundaryInterval(
                    intervals: $intervals,
                    endsAt: $slotEndsAt,
                );

                if ($endInterval instanceof AvailabilityInterval) {
                    $availability = $this->rangeBoundaryCoverage(
                        startsAt: $slotStartsAt,
                        endsAt: $slotEndsAt,
                        startInterval: $startInterval,
                        endInterval: $endInterval,
                    );
                    $slot = $this->bookableSlot(
                        search: $search,
                        host: $host,
                        assignment: $assignment,
                        candidateLocation: $candidateLocation,
                        availability: $availability,
                        startsAt: $slotStartsAt,
                        endsAt: $slotEndsAt,
                        appointments: $appointments,
                        holds: $holds,
                    );

                    if ($slot instanceof BookableSlot) {
                        $slots[] = $slot;
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
     */
    private function rangeEndBoundaryInterval(
        array $intervals,
        CarbonImmutable $endsAt,
    ): ?AvailabilityInterval {
        $startingAtBoundary = null;

        foreach ($intervals as $interval) {
            if ($interval->startsAt->lessThan($endsAt)
                && $interval->endsAt->greaterThanOrEqualTo($endsAt)
            ) {
                return $interval;
            }

            if ($interval->startsAt->equalTo($endsAt)) {
                $startingAtBoundary ??= $interval;
            }
        }

        return $startingAtBoundary;
    }

    private function rangeBoundaryCoverage(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        AvailabilityInterval $startInterval,
        AvailabilityInterval $endInterval,
    ): AvailabilityInterval {
        $capacities = array_values(array_filter([
            $startInterval->capacity,
            $endInterval->capacity,
        ], static fn (?int $capacity): bool => $capacity !== null));

        return new AvailabilityInterval(
            startsAt: $startsAt,
            endsAt: $endsAt,
            hostId: $startInterval->hostId,
            capacity: $capacities === [] ? null : min($capacities),
            sourceScopes: [
                ...$startInterval->sourceScopes,
                ...$endInterval->sourceScopes,
            ],
            sourceWindowIds: [
                ...$startInterval->sourceWindowIds,
                ...$endInterval->sourceWindowIds,
            ],
            sourceTimezones: [
                ...$startInterval->sourceTimezones,
                ...$endInterval->sourceTimezones,
            ],
        );
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Appointment> $appointments
     * @param \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Scheduling\Models\BookingHold> $holds
     */
    private function bookableSlot(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
        ?SchedulingLocationSnapshot $candidateLocation,
        AvailabilityInterval $availability,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        \Illuminate\Database\Eloquent\Collection $appointments,
        \Illuminate\Database\Eloquent\Collection $holds,
    ): ?BookableSlot {
        $resourceCapacity = $this->occupancy->resourceCapacity(
            search: $search,
            host: $host,
            startsAt: $startsAt,
            endsAt: $endsAt,
        );
        $capacity = $this->effectiveCapacity(
            service: $search->service,
            host: $host,
            assignment: $assignment,
            availability: $availability,
            resourceCapacity: $resourceCapacity['capacity'],
        );
        $remainingCapacity = $this->occupancy->remainingCapacity(
            service: $search->service,
            host: $host,
            assignment: $assignment,
            availability: $availability,
            startsAt: $startsAt,
            endsAt: $endsAt,
            appointments: $appointments,
            holds: $holds,
            resourceRemainingCapacity: $resourceCapacity['remaining'],
        );

        if ($remainingCapacity < 1) {
            return null;
        }

        $travel = $this->travel->assess(
            search: $search,
            host: $host,
            startsAt: $startsAt,
            endsAt: $endsAt,
            candidateLocation: $candidateLocation,
            appointments: $appointments,
            holds: $holds,
        );

        if (! $travel->feasible) {
            return null;
        }

        return new BookableSlot(
            bookableServiceId: (int) $search->service->getKey(),
            schedulingHostId: $host?->getKey(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            displayTimezone: $search->displayTimezone,
            capacity: $capacity,
            remainingCapacity: min($capacity, $remainingCapacity),
            sourceScopes: $availability->sourceScopes,
            sourceWindowIds: $availability->sourceWindowIds,
            travelMinutesBefore: $travel->minutesBefore,
            travelMinutesAfter: $travel->minutesAfter,
        );
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
        ?int $resourceCapacity,
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

        if ($resourceCapacity !== null) {
            $capacities[] = max(0, $resourceCapacity);
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