<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class BookingOccupancyResolver
{
    public function __construct(
        private readonly AppointmentOccupancyResolver $appointments,
    ) {}

    /**
     * @return Collection<int, Appointment>
     */
    public function blockingAppointments(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): Collection {
        return $this->appointments->blockingAppointments($search, $host);
    }

    /**
     * @return Collection<int, BookingHold>
     */
    public function activeHolds(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): Collection {
        $candidateBefore = max(0, (int) $search->service->buffer_before_minutes);
        $candidateAfter = max(0, (int) $search->service->buffer_after_minutes);

        $query = BookingHold::query()
            ->effectivelyActive($search->evaluatedAt)
            ->overlappingOccupancy(
                $search->effectiveStartsAt->subMinutes($candidateBefore),
                $search->effectiveEndsAt->addMinutes($candidateAfter),
            );

        if ($host !== null) {
            $query->where('scheduling_host_id', $host->getKey());
        } else {
            $query
                ->where('bookable_service_id', $search->service->getKey())
                ->whereNull('scheduling_host_id');
        }

        return $query
            ->orderBy('occupancy_starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int, Appointment> $appointments
     * @param Collection<int, BookingHold> $holds
     */
    public function remainingCapacity(
        BookableService $service,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
        AvailabilityInterval $availability,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        Collection $appointments,
        Collection $holds,
    ): int {
        $candidateStartsAt = $startsAt->subMinutes(
            max(0, (int) $service->buffer_before_minutes),
        );
        $candidateEndsAt = $endsAt->addMinutes(
            max(0, (int) $service->buffer_after_minutes),
        );
        $hostId = $host?->getKey();
        $hostOccupancy = 0;
        $serviceHostOccupancy = 0;

        foreach ($appointments as $appointment) {
            if (! $this->appointmentBlocks(
                appointment: $appointment,
                candidateStartsAt: $candidateStartsAt,
                candidateEndsAt: $candidateEndsAt,
            )) {
                continue;
            }

            if ($hostId !== null
                && (int) $appointment->scheduling_host_id === (int) $hostId
            ) {
                $hostOccupancy++;
            }

            if ((int) $appointment->bookable_service_id === (int) $service->getKey()
                && $this->sameHost($appointment->scheduling_host_id, $hostId)
            ) {
                $serviceHostOccupancy++;
            }
        }

        foreach ($holds as $hold) {
            if (! $this->holdBlocks(
                hold: $hold,
                candidateStartsAt: $candidateStartsAt,
                candidateEndsAt: $candidateEndsAt,
            )) {
                continue;
            }

            if ($hostId !== null
                && (int) $hold->scheduling_host_id === (int) $hostId
            ) {
                $hostOccupancy++;
            }

            if ((int) $hold->bookable_service_id === (int) $service->getKey()
                && $this->sameHost($hold->scheduling_host_id, $hostId)
            ) {
                $serviceHostOccupancy++;
            }
        }

        $remaining = [
            max(0, max(1, (int) $service->capacity) - $serviceHostOccupancy),
        ];

        if ($host !== null) {
            $remaining[] = max(
                0,
                max(1, (int) $host->capacity) - $hostOccupancy,
            );
        }

        if ($assignment?->capacity_override !== null) {
            $remaining[] = max(
                0,
                max(1, (int) $assignment->capacity_override) - $serviceHostOccupancy,
            );
        }

        if ($availability->capacity !== null) {
            $remaining[] = max(
                0,
                max(1, $availability->capacity) - $serviceHostOccupancy,
            );
        }

        return min($remaining);
    }

    private function appointmentBlocks(
        Appointment $appointment,
        CarbonImmutable $candidateStartsAt,
        CarbonImmutable $candidateEndsAt,
    ): bool {
        if ($appointment->starts_at === null || $appointment->ends_at === null) {
            return false;
        }

        $appointmentService = $appointment->bookableService;
        $bufferBefore = max(
            0,
            (int) ($appointmentService?->buffer_before_minutes ?? 0),
        );
        $bufferAfter = max(
            0,
            (int) ($appointmentService?->buffer_after_minutes ?? 0),
        );
        $occupiedStartsAt = CarbonImmutable::instance($appointment->starts_at)
            ->utc()
            ->subMinutes($bufferBefore);
        $occupiedEndsAt = CarbonImmutable::instance($appointment->ends_at)
            ->utc()
            ->addMinutes($bufferAfter);

        return $occupiedStartsAt->lessThan($candidateEndsAt)
            && $occupiedEndsAt->greaterThan($candidateStartsAt);
    }

    private function holdBlocks(
        BookingHold $hold,
        CarbonImmutable $candidateStartsAt,
        CarbonImmutable $candidateEndsAt,
    ): bool {
        if ($hold->occupancy_starts_at === null || $hold->occupancy_ends_at === null) {
            return false;
        }

        return CarbonImmutable::instance($hold->occupancy_starts_at)
            ->utc()
            ->lessThan($candidateEndsAt)
            && CarbonImmutable::instance($hold->occupancy_ends_at)
                ->utc()
                ->greaterThan($candidateStartsAt);
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }
}