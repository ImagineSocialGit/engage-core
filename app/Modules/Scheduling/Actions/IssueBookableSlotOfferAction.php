<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingDurationResolver;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IssueBookableSlotOfferAction
{
    private const RESCHEDULABLE_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly SchedulingDurationResolver $durations,
        private readonly SchedulingLocationSnapshotResolver $locations,
    ) {}

    public function handle(
        BookableSlot $slot,
        ?CarbonInterface $issuedAt = null,
        ?Appointment $rescheduleAppointment = null,
        ?SchedulingLocationSnapshot $location = null,
    ): BookableSlotOffer {
        $issuedAt = $issuedAt !== null
            ? CarbonImmutable::instance($issuedAt)->utc()
            : CarbonImmutable::now('UTC');

        return DB::transaction(function () use (
            $slot,
            $issuedAt,
            $rescheduleAppointment,
            $location,
        ): BookableSlotOffer {
            $service = BookableService::withTrashed()
                ->whereKey($slot->bookableServiceId)
                ->lockForUpdate()
                ->first();

            if (! $service instanceof BookableService
                || $service->trashed()
                || $service->status !== BookableService::STATUS_ACTIVE
            ) {
                throw new DomainException(
                    'The selected service is no longer available for booking.',
                );
            }

            $host = $this->lockedHost($slot);
            $rescheduleAppointment = $this->lockedRescheduleAppointment(
                service: $service,
                appointment: $rescheduleAppointment,
            );
            $locationSnapshot = $location;

            if ($location instanceof SchedulingLocationSnapshot
                || $service->location_type !== BookableService::LOCATION_TYPE_CUSTOMER_SITE
                || $rescheduleAppointment instanceof Appointment
            ) {
                $locationSnapshot = $this->locations->forCommitment(
                    service: $service,
                    requested: $location,
                    rescheduleSource: $rescheduleAppointment,
                );
            }
            $currentSlot = $this->exactCurrentSlot(
                service: $service,
                host: $host,
                slot: $slot,
                evaluatedAt: $issuedAt,
                rescheduleAppointment: $rescheduleAppointment,
                location: $locationSnapshot,
            );

            if (! $currentSlot instanceof BookableSlot) {
                throw new DomainException(
                    'The selected slot is no longer available.',
                );
            }

            $ttlSeconds = max(
                1,
                (int) config('scheduling.slot_offers.ttl_seconds', 300),
            );

            return BookableSlotOffer::query()->create([
                'bookable_service_id' => $currentSlot->bookableServiceId,
                'scheduling_host_id' => $currentSlot->schedulingHostId,
                'reschedule_appointment_id' => $rescheduleAppointment?->getKey(),
                'starts_at' => $currentSlot->startsAt,
                'ends_at' => $currentSlot->endsAt,
                'display_timezone' => $currentSlot->displayTimezone,
                'capacity' => $currentSlot->capacity,
                'remaining_capacity' => $currentSlot->remainingCapacity,
                'location_type' => $locationSnapshot?->type,
                'location_details' => $locationSnapshot?->details,
                'source_scopes' => $currentSlot->sourceScopes,
                'source_window_ids' => $currentSlot->sourceWindowIds,
                'issued_at' => $issuedAt,
                'expires_at' => $issuedAt->addSeconds($ttlSeconds),
            ]);
        });
    }

    private function lockedHost(BookableSlot $slot): ?SchedulingHost
    {
        if ($slot->schedulingHostId === null) {
            return null;
        }

        $host = SchedulingHost::withTrashed()
            ->whereKey($slot->schedulingHostId)
            ->lockForUpdate()
            ->first();

        if (! $host instanceof SchedulingHost
            || $host->trashed()
            || $host->status !== SchedulingHost::STATUS_ACTIVE
        ) {
            throw new DomainException(
                'The selected scheduling host is no longer available.',
            );
        }

        return $host;
    }

    private function lockedRescheduleAppointment(
        BookableService $service,
        ?Appointment $appointment,
    ): ?Appointment {
        if ($appointment === null) {
            return null;
        }

        if (! $appointment->exists || $appointment->getKey() === null) {
            throw new InvalidArgumentException(
                'Reschedule slot offers require a persisted Appointment.',
            );
        }

        $locked = Appointment::withTrashed()
            ->whereKey($appointment->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Appointment || $locked->trashed()) {
            throw new DomainException(
                'The appointment selected for rescheduling could not be found.',
            );
        }

        if ((int) $locked->bookable_service_id !== (int) $service->getKey()) {
            throw new DomainException(
                'The appointment selected for rescheduling belongs to another service.',
            );
        }

        if (! in_array($locked->status, self::RESCHEDULABLE_STATUSES, true)) {
            throw new DomainException(
                "Appointment status [{$locked->status}] cannot be rescheduled.",
            );
        }

        if ($this->hasReplacement($locked)) {
            throw new DomainException(
                'The appointment has already been rescheduled.',
            );
        }

        return $locked;
    }

    private function hasReplacement(Appointment $appointment): bool
    {
        return Appointment::withTrashed()
            ->where('rescheduled_from_id', $appointment->getKey())
            ->exists();
    }

    private function exactCurrentSlot(
        BookableService $service,
        ?SchedulingHost $host,
        BookableSlot $slot,
        CarbonImmutable $evaluatedAt,
        ?Appointment $rescheduleAppointment,
        ?SchedulingLocationSnapshot $location,
    ): ?BookableSlot {
        $candidateDurationMinutes = $this->durations->durationMinutes(
            service: $service,
            startsAt: $slot->startsAt,
            endsAt: $slot->endsAt,
        );
        $search = new AvailabilitySearch(
            service: $service,
            startsAt: $slot->startsAt,
            endsAt: $slot->endsAt,
            host: $host,
            displayTimezone: $slot->displayTimezone,
            evaluatedAt: $evaluatedAt,
            rescheduleAppointment: $rescheduleAppointment,
            location: $location,
            candidateDurationMinutes: $candidateDurationMinutes,
        );

        foreach ($this->findAvailability->handle($search) as $candidate) {
            if ($candidate->bookableServiceId === $slot->bookableServiceId
                && $candidate->schedulingHostId === $slot->schedulingHostId
                && $candidate->startsAt->equalTo($slot->startsAt)
                && $candidate->endsAt->equalTo($slot->endsAt)
            ) {
                return $candidate;
            }
        }

        return null;
    }
}