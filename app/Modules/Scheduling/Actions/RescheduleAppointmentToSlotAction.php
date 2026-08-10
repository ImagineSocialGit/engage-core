<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Data\AppointmentRescheduleData;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingDurationResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class RescheduleAppointmentToSlotAction
{
    private const RESCHEDULABLE_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly IssueBookableSlotOfferAction $issueSlotOffer,
        private readonly CreateBookingHoldAction $createBookingHold,
        private readonly RescheduleAppointmentAction $rescheduleAppointment,
        private readonly SchedulingDurationResolver $durations,
    ) {}

    public function handle(
        Appointment $appointment,
        CarbonInterface $startsAt,
        string $idempotencyKey,
        AppointmentLifecycleContext $lifecycle,
        ?SchedulingHost $host = null,
        bool $preserveConfirmation = false,
    ): Appointment {
        $appointmentId = $this->requiredAppointmentId($appointment);
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $idempotencyKey = $this->requiredIdempotencyKey($idempotencyKey);
        $hostId = $this->persistedHostId($host);

        $existing = $this->existingHold($idempotencyKey);

        if ($existing instanceof BookingHold) {
            return $this->resumeMatchingHold(
                hold: $existing,
                appointmentId: $appointmentId,
                startsAt: $startsAt,
                hostId: $hostId,
                lifecycle: $lifecycle,
                preserveConfirmation: $preserveConfirmation,
            );
        }

        try {
            return DB::transaction(function () use (
                $appointmentId,
                $startsAt,
                $idempotencyKey,
                $lifecycle,
                $host,
                $hostId,
                $preserveConfirmation,
            ): Appointment {
                $existing = $this->existingHold(
                    idempotencyKey: $idempotencyKey,
                    lock: true,
                );

                if ($existing instanceof BookingHold) {
                    return $this->resumeMatchingHold(
                        hold: $existing,
                        appointmentId: $appointmentId,
                        startsAt: $startsAt,
                        hostId: $hostId,
                        lifecycle: $lifecycle,
                        preserveConfirmation: $preserveConfirmation,
                    );
                }

                $snapshot = Appointment::withTrashed()
                    ->whereKey($appointmentId)
                    ->first(['id', 'bookable_service_id']);

                if (! $snapshot instanceof Appointment) {
                    throw new DomainException(
                        'The appointment selected for rescheduling could not be found.',
                    );
                }

                $service = BookableService::withTrashed()
                    ->whereKey($snapshot->bookable_service_id)
                    ->lockForUpdate()
                    ->first();

                if (! $service instanceof BookableService
                    || $service->trashed()
                    || $service->status !== BookableService::STATUS_ACTIVE
                ) {
                    throw new DomainException(
                        'The appointment service is no longer available for rescheduling.',
                    );
                }

                $original = Appointment::withTrashed()
                    ->whereKey($appointmentId)
                    ->lockForUpdate()
                    ->first();

                if (! $original instanceof Appointment || $original->trashed()) {
                    throw new DomainException(
                        'The appointment selected for rescheduling could not be found.',
                    );
                }

                if ((int) $original->bookable_service_id !== (int) $service->getKey()) {
                    throw new LogicException(
                        'The appointment service changed while rescheduling was prepared.',
                    );
                }

                if (! in_array($original->status, self::RESCHEDULABLE_STATUSES, true)) {
                    throw new DomainException(
                        "Appointment status [{$original->status}] cannot be rescheduled.",
                    );
                }

                if (Appointment::withTrashed()
                    ->where('rescheduled_from_id', $original->getKey())
                    ->exists()
                ) {
                    throw new DomainException(
                        'The appointment has already been rescheduled.',
                    );
                }

                $this->assertHostSelection(
                    service: $service,
                    host: $host,
                );

                if ($this->sameTarget($original, $startsAt, $hostId)) {
                    throw new DomainException(
                        'Choose a different appointment time or scheduling host.',
                    );
                }

                $slot = $this->exactCurrentSlot(
                    appointment: $original,
                    service: $service,
                    host: $host,
                    startsAt: $startsAt,
                    evaluatedAt: $lifecycle->occurredAt,
                );

                if (! $slot instanceof BookableSlot) {
                    throw new DomainException(
                        'The selected reschedule time is no longer available.',
                    );
                }

                $offer = $this->issueSlotOffer->handle(
                    slot: $slot,
                    issuedAt: $lifecycle->occurredAt,
                    rescheduleAppointment: $original,
                );
                $hold = $this->createBookingHold->handle(
                    offerId: $offer->offer_id,
                    idempotencyKey: $idempotencyKey,
                );

                return $this->resumeMatchingHold(
                    hold: $hold->loadMissing('bookableSlotOffer'),
                    appointmentId: $appointmentId,
                    startsAt: $startsAt,
                    hostId: $hostId,
                    lifecycle: $lifecycle,
                    preserveConfirmation: $preserveConfirmation,
                );
            }, 3);
        } catch (QueryException|LogicException $exception) {
            $existing = $this->existingHold($idempotencyKey);

            if ($existing instanceof BookingHold) {
                return $this->resumeMatchingHold(
                    hold: $existing,
                    appointmentId: $appointmentId,
                    startsAt: $startsAt,
                    hostId: $hostId,
                    lifecycle: $lifecycle,
                    preserveConfirmation: $preserveConfirmation,
                );
            }

            throw $exception;
        }
    }

    private function resumeMatchingHold(
        BookingHold $hold,
        int $appointmentId,
        CarbonImmutable $startsAt,
        ?int $hostId,
        AppointmentLifecycleContext $lifecycle,
        bool $preserveConfirmation,
    ): Appointment {
        $offer = $hold->bookableSlotOffer;

        if ($offer === null
            || (int) $offer->reschedule_appointment_id !== $appointmentId
            || (int) $hold->bookable_service_id !== (int) $offer->bookable_service_id
            || ! $this->sameHost($hold->scheduling_host_id, $hostId)
            || ! $hold->starts_at?->equalTo($startsAt)
            || ! $offer->starts_at?->equalTo($startsAt)
        ) {
            throw new LogicException(
                'The reschedule replay key was already used for another request.',
            );
        }

        return $this->rescheduleAppointment->handle(new AppointmentRescheduleData(
            holdId: $hold->hold_id,
            lifecycle: $lifecycle,
            preserveConfirmation: $preserveConfirmation,
        ));
    }

    private function exactCurrentSlot(
        Appointment $appointment,
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $evaluatedAt,
    ): ?BookableSlot {
        $candidateDurationMinutes = $this->durations->rescheduleDurationMinutes(
            service: $service,
            appointment: $appointment,
        );
        $endsAt = $startsAt->addMinutes($candidateDurationMinutes);
        $search = new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $endsAt,
            host: $host,
            displayTimezone: $service->timezone,
            evaluatedAt: $evaluatedAt,
            rescheduleAppointment: $appointment,
            candidateDurationMinutes: $candidateDurationMinutes,
        );

        foreach ($this->findAvailability->handle($search) as $slot) {
            if ($slot->bookableServiceId === (int) $service->getKey()
                && $slot->schedulingHostId === $host?->getKey()
                && $slot->startsAt->equalTo($startsAt)
                && $slot->endsAt->equalTo($endsAt)
            ) {
                return $slot;
            }
        }

        return null;
    }

    private function assertHostSelection(
        BookableService $service,
        ?SchedulingHost $host,
    ): void {
        $hasAssignments = BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->exists();

        if ($host === null) {
            if ($hasAssignments) {
                throw new DomainException(
                    'The selected service requires an explicit assigned scheduling host.',
                );
            }

            return;
        }

        if (! $host->exists || $host->getKey() === null) {
            throw new InvalidArgumentException(
                'Appointment rescheduling requires a persisted SchedulingHost.',
            );
        }

        $eligible = SchedulingHost::query()
            ->whereKey($host->getKey())
            ->where('status', SchedulingHost::STATUS_ACTIVE)
            ->whereHas('serviceAssignments', function ($query) use ($service): void {
                $query
                    ->where('bookable_service_id', $service->getKey())
                    ->where('is_active', true);
            })
            ->exists();

        if (! $eligible) {
            throw new DomainException(
                'The selected scheduling host is not actively assigned to this service.',
            );
        }
    }

    private function existingHold(
        string $idempotencyKey,
        bool $lock = false,
    ): ?BookingHold {
        $query = BookingHold::query()
            ->with('bookableSlotOffer')
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function sameTarget(
        Appointment $appointment,
        CarbonImmutable $startsAt,
        ?int $hostId,
    ): bool {
        return $appointment->starts_at?->equalTo($startsAt)
            && $this->sameHost($appointment->scheduling_host_id, $hostId);
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }

    private function requiredAppointmentId(Appointment $appointment): int
    {
        if (! $appointment->exists || $appointment->getKey() === null) {
            throw new InvalidArgumentException(
                'Appointment rescheduling requires a persisted Appointment.',
            );
        }

        return (int) $appointment->getKey();
    }

    private function persistedHostId(?SchedulingHost $host): ?int
    {
        if ($host === null) {
            return null;
        }

        if (! $host->exists || $host->getKey() === null) {
            throw new InvalidArgumentException(
                'Appointment rescheduling requires a persisted SchedulingHost.',
            );
        }

        return (int) $host->getKey();
    }

    private function requiredIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException(
                'A non-empty appointment reschedule replay key is required.',
            );
        }

        if (mb_strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException(
                'The appointment reschedule replay key cannot exceed 191 characters.',
            );
        }

        return $idempotencyKey;
    }
}