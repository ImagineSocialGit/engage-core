<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class AppointmentOccupancyResolver
{
    /**
     * @return Collection<int, Appointment>
     */
    public function blockingAppointments(
        AvailabilitySearch $search,
        ?SchedulingHost $host,
    ): Collection {
        $maximumBefore = max(0, (int) BookableService::withTrashed()->max('buffer_before_minutes'));
        $maximumAfter = max(0, (int) BookableService::withTrashed()->max('buffer_after_minutes'));
        $candidateBefore = max(0, (int) $search->service->buffer_before_minutes);
        $candidateAfter = max(0, (int) $search->service->buffer_after_minutes);

        $query = Appointment::query()
            ->with([
                'bookableService' => fn ($query) => $query->withTrashed(),
            ])
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '<', $search->effectiveEndsAt
                ->addMinutes($candidateAfter + $maximumBefore))
            ->where('ends_at', '>', $search->effectiveStartsAt
                ->subMinutes($candidateBefore + $maximumAfter));

        if ($search->rescheduleAppointment !== null) {
            $query->where(
                'id',
                '!=',
                $search->rescheduleAppointment->getKey(),
            );
        }

        if ($host !== null) {
            $query->where('scheduling_host_id', $host->getKey());
        } else {
            $query
                ->where('bookable_service_id', $search->service->getKey())
                ->whereNull('scheduling_host_id');
        }

        return $query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int, Appointment> $appointments
     */
    public function remainingCapacity(
        BookableService $service,
        ?SchedulingHost $host,
        ?BookableServiceHost $assignment,
        AvailabilityInterval $availability,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        Collection $appointments,
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
            if (! $this->blocks(
                appointment: $appointment,
                candidateStartsAt: $candidateStartsAt,
                candidateEndsAt: $candidateEndsAt,
            )) {
                continue;
            }

            if ($hostId !== null && (int) $appointment->scheduling_host_id === (int) $hostId) {
                $hostOccupancy++;
            }

            if ((int) $appointment->bookable_service_id === (int) $service->getKey()
                && $this->sameHost($appointment->scheduling_host_id, $hostId)
            ) {
                $serviceHostOccupancy++;
            }
        }

        $remaining = [
            max(0, max(1, (int) $service->capacity) - $serviceHostOccupancy),
        ];

        if ($host !== null) {
            $remaining[] = max(0, max(1, (int) $host->capacity) - $hostOccupancy);
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

    private function blocks(
        Appointment $appointment,
        CarbonImmutable $candidateStartsAt,
        CarbonImmutable $candidateEndsAt,
    ): bool {
        if ($appointment->starts_at === null || $appointment->ends_at === null) {
            return false;
        }

        $appointmentService = $appointment->bookableService;
        $bufferBefore = max(0, (int) ($appointmentService?->buffer_before_minutes ?? 0));
        $bufferAfter = max(0, (int) ($appointmentService?->buffer_after_minutes ?? 0));
        $occupiedStartsAt = CarbonImmutable::instance($appointment->starts_at)
            ->utc()
            ->subMinutes($bufferBefore);
        $occupiedEndsAt = CarbonImmutable::instance($appointment->ends_at)
            ->utc()
            ->addMinutes($bufferAfter);

        return $occupiedStartsAt->lessThan($candidateEndsAt)
            && $occupiedEndsAt->greaterThan($candidateStartsAt);
    }

    private function sameHost(mixed $appointmentHostId, mixed $candidateHostId): bool
    {
        if ($appointmentHostId === null || $candidateHostId === null) {
            return $appointmentHostId === null && $candidateHostId === null;
        }

        return (int) $appointmentHostId === (int) $candidateHostId;
    }
}