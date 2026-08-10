<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelFeasibility;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;

final class TravelFeasibilityResolver
{
    public function __construct(
        private readonly SchedulingTravelTimeResolver $travelTimes,
    ) {}

    /**
     * @param Collection<int, Appointment> $appointments
     * @param Collection<int, BookingHold> $holds
     */
    public function assess(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?SchedulingLocationSnapshot $candidateLocation,
        Collection $appointments,
        Collection $holds,
    ): TravelFeasibility {
        if ($host === null
            || ! $candidateLocation instanceof SchedulingLocationSnapshot
            || ! $candidateLocation->isPhysical()
        ) {
            return TravelFeasibility::unconstrained();
        }

        $candidateOccupiedStartsAt = $startsAt->subMinutes(
            max(0, (int) $search->service->buffer_before_minutes),
        );
        $candidateOccupiedEndsAt = $endsAt->addMinutes(
            max(0, (int) $search->service->buffer_after_minutes),
        );
        $commitments = [];

        foreach ($appointments as $appointment) {
            $commitment = $this->appointmentCommitment($search, $appointment);

            if ($commitment !== null) {
                $commitments[] = $commitment;
            }
        }

        foreach ($holds as $hold) {
            $commitment = $this->holdCommitment($hold);

            if ($commitment !== null) {
                $commitments[] = $commitment;
            }
        }

        $previous = null;
        $next = null;

        foreach ($commitments as $commitment) {
            if ($commitment['starts_at']->lessThan($endsAt)
                && $commitment['ends_at']->greaterThan($startsAt)
            ) {
                if (! $this->sameAddress($commitment['location'], $candidateLocation)) {
                    return new TravelFeasibility(
                        feasible: false,
                        reason: 'overlapping_physical_commitment',
                    );
                }

                continue;
            }

            if ($commitment['ends_at']->lessThanOrEqualTo($startsAt)) {
                if ($previous === null
                    || $commitment['occupied_ends_at']->greaterThan($previous['occupied_ends_at'])
                ) {
                    $previous = $commitment;
                }

                continue;
            }

            if ($commitment['starts_at']->greaterThanOrEqualTo($endsAt)
                && ($next === null
                    || $commitment['occupied_starts_at']->lessThan($next['occupied_starts_at']))
            ) {
                $next = $commitment;
            }
        }

        $minutesBefore = null;
        $sourceBefore = null;

        if ($previous !== null) {
            $estimate = $this->travelTimes->estimate(
                $previous['location'],
                $candidateLocation,
            );
            $this->assertEstimateWithinPolicy($estimate->minutes);
            $minutesBefore = $estimate->minutes;
            $sourceBefore = $estimate->source;
            $availableMinutes = $previous['occupied_ends_at']->diffInMinutes(
                $candidateOccupiedStartsAt,
                false,
            );

            if ($availableMinutes < $estimate->minutes) {
                return new TravelFeasibility(
                    feasible: false,
                    minutesBefore: $minutesBefore,
                    sourceBefore: $sourceBefore,
                    reason: 'insufficient_travel_time_before',
                );
            }
        }

        $minutesAfter = null;
        $sourceAfter = null;

        if ($next !== null) {
            $estimate = $this->travelTimes->estimate(
                $candidateLocation,
                $next['location'],
            );
            $this->assertEstimateWithinPolicy($estimate->minutes);
            $minutesAfter = $estimate->minutes;
            $sourceAfter = $estimate->source;
            $availableMinutes = $candidateOccupiedEndsAt->diffInMinutes(
                $next['occupied_starts_at'],
                false,
            );

            if ($availableMinutes < $estimate->minutes) {
                return new TravelFeasibility(
                    feasible: false,
                    minutesBefore: $minutesBefore,
                    minutesAfter: $minutesAfter,
                    sourceBefore: $sourceBefore,
                    sourceAfter: $sourceAfter,
                    reason: 'insufficient_travel_time_after',
                );
            }
        }

        return new TravelFeasibility(
            feasible: true,
            minutesBefore: $minutesBefore,
            minutesAfter: $minutesAfter,
            sourceBefore: $sourceBefore,
            sourceAfter: $sourceAfter,
        );
    }

    /**
     * @return array{
     *     starts_at: CarbonImmutable,
     *     ends_at: CarbonImmutable,
     *     occupied_starts_at: CarbonImmutable,
     *     occupied_ends_at: CarbonImmutable,
     *     location: SchedulingLocationSnapshot,
     * }|null
     */
    private function appointmentCommitment(
        AvailabilitySearch $search,
        Appointment $appointment,
    ): ?array {
        if ($search->rescheduleAppointment !== null
            && (int) $appointment->getKey() === (int) $search->rescheduleAppointment->getKey()
        ) {
            return null;
        }

        if ($appointment->starts_at === null || $appointment->ends_at === null) {
            return null;
        }

        try {
            $location = $appointment->locationSnapshot();
        } catch (InvalidArgumentException) {
            throw new LogicException(
                'A physical Appointment has an invalid Scheduling location snapshot.',
            );
        }

        if (! $location instanceof SchedulingLocationSnapshot || ! $location->isPhysical()) {
            return null;
        }

        $startsAt = CarbonImmutable::instance($appointment->starts_at)->utc();
        $endsAt = CarbonImmutable::instance($appointment->ends_at)->utc();
        $service = $appointment->bookableService;
        $bufferBefore = max(0, (int) ($service?->buffer_before_minutes ?? 0));
        $bufferAfter = max(0, (int) ($service?->buffer_after_minutes ?? 0));

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'occupied_starts_at' => $startsAt->subMinutes($bufferBefore),
            'occupied_ends_at' => $endsAt->addMinutes($bufferAfter),
            'location' => $location,
        ];
    }

    /**
     * @return array{
     *     starts_at: CarbonImmutable,
     *     ends_at: CarbonImmutable,
     *     occupied_starts_at: CarbonImmutable,
     *     occupied_ends_at: CarbonImmutable,
     *     location: SchedulingLocationSnapshot,
     * }|null
     */
    private function holdCommitment(BookingHold $hold): ?array
    {
        if ($hold->starts_at === null
            || $hold->ends_at === null
            || $hold->occupancy_starts_at === null
            || $hold->occupancy_ends_at === null
        ) {
            return null;
        }

        try {
            $location = $hold->locationSnapshot();
        } catch (InvalidArgumentException) {
            throw new LogicException(
                'A physical BookingHold has an invalid Scheduling location snapshot.',
            );
        }

        if (! $location instanceof SchedulingLocationSnapshot || ! $location->isPhysical()) {
            return null;
        }

        return [
            'starts_at' => CarbonImmutable::instance($hold->starts_at)->utc(),
            'ends_at' => CarbonImmutable::instance($hold->ends_at)->utc(),
            'occupied_starts_at' => CarbonImmutable::instance($hold->occupancy_starts_at)->utc(),
            'occupied_ends_at' => CarbonImmutable::instance($hold->occupancy_ends_at)->utc(),
            'location' => $location,
        ];
    }

    private function sameAddress(
        SchedulingLocationSnapshot $left,
        SchedulingLocationSnapshot $right,
    ): bool {
        $leftAddress = data_get($left->details, 'address');
        $rightAddress = data_get($right->details, 'address');

        return is_array($leftAddress)
            && is_array($rightAddress)
            && $leftAddress === $rightAddress;
    }

    private function assertEstimateWithinPolicy(int $minutes): void
    {
        $maximum = max(
            0,
            min(1440, (int) config('scheduling.travel.maximum_minutes', 240)),
        );

        if ($minutes > $maximum) {
            throw new LogicException(sprintf(
                'TravelTimeResolver returned %d minutes, above the configured Scheduling travel maximum of %d minutes.',
                $minutes,
                $maximum,
            ));
        }
    }
}