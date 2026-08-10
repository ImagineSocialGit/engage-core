<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\BookingOccupancyResolver;
use App\Modules\Scheduling\Services\Availability\ResourceOccupancyResolver;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class CreateBookingHoldAction
{
    private const RESCHEDULABLE_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly BookingOccupancyResolver $occupancy,
        private readonly ResourceOccupancyResolver $resourceOccupancy,
        private readonly SchedulingLocationSnapshotResolver $locations,
    ) {}

    public function handle(
        string $offerId,
        string $idempotencyKey,
        ?SchedulingLocationSnapshot $location = null,
    ): BookingHold {
        $offerId = $this->requiredString($offerId, 'offer ID', 36);
        $idempotencyKey = $this->requiredString(
            $idempotencyKey,
            'booking hold idempotency key',
            191,
        );

        try {
            return DB::transaction(function () use ($offerId, $idempotencyKey, $location): BookingHold {
                $now = CarbonImmutable::now('UTC');
                $offer = BookableSlotOffer::query()
                    ->where('offer_id', $offerId)
                    ->lockForUpdate()
                    ->first();

                if (! $offer instanceof BookableSlotOffer) {
                    throw new DomainException('The selected slot offer could not be found.');
                }

                $existing = BookingHold::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof BookingHold) {
                    if ((int) $existing->bookable_slot_offer_id !== (int) $offer->getKey()) {
                        throw new LogicException(
                            'The booking hold idempotency key was already used for another slot offer.',
                        );
                    }

                    $this->assertRequestedLocationMatches($existing, $location);

                    return $existing;
                }

                if (! $offer->isActiveAt($now)) {
                    throw new DomainException('The selected slot offer has expired or was already used.');
                }

                $service = BookableService::withTrashed()
                    ->whereKey($offer->bookable_service_id)
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

                $host = $this->lockedTarget($offer, $service);
                $rescheduleAppointment = $this->lockedRescheduleAppointment(
                    offer: $offer,
                    service: $service,
                );
                $locationSnapshot = $this->locations->forCommitment(
                    service: $service,
                    requested: $location,
                    rescheduleSource: $rescheduleAppointment,
                );
                $resourceSnapshot = $this->resourceOccupancy->lockRequirementSnapshot(
                    service: $service,
                    host: $host,
                );
                $search = new AvailabilitySearch(
                    service: $service,
                    startsAt: $offer->starts_at,
                    endsAt: $offer->ends_at,
                    host: $host,
                    displayTimezone: $offer->display_timezone,
                    evaluatedAt: $now,
                    rescheduleAppointment: $rescheduleAppointment,
                    location: $locationSnapshot,
                );

                $appointments = $this->occupancy
                    ->blockingAppointments($search, $host);

                $this->lockAppointments($appointments);

                $occupancyStartsAt = CarbonImmutable::instance($offer->starts_at)
                    ->utc()
                    ->subMinutes(max(0, (int) $service->buffer_before_minutes));
                $occupancyEndsAt = CarbonImmutable::instance($offer->ends_at)
                    ->utc()
                    ->addMinutes(max(0, (int) $service->buffer_after_minutes));

                $holds = $this->occupancy->activeHolds($search, $host);
                $this->lockHolds($holds);

                $currentSlot = $this->exactCurrentSlot($search, $offer);

                if (! $currentSlot instanceof BookableSlot) {
                    throw new DomainException(
                        'The selected slot is no longer available or no longer has available capacity.',
                    );
                }

                $ttlSeconds = max(
                    1,
                    (int) config('scheduling.booking_holds.ttl_seconds', 600),
                );

                $hold = BookingHold::query()->create([
                    'bookable_slot_offer_id' => $offer->getKey(),
                    'bookable_service_id' => $service->getKey(),
                    'scheduling_host_id' => $host?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => BookingHold::STATUS_ACTIVE,
                    'starts_at' => $currentSlot->startsAt,
                    'ends_at' => $currentSlot->endsAt,
                    'occupancy_starts_at' => $occupancyStartsAt,
                    'occupancy_ends_at' => $occupancyEndsAt,
                    'capacity' => $currentSlot->capacity,
                    'location_type' => $locationSnapshot?->type,
                    'location_details' => $locationSnapshot?->details,
                    'held_at' => $now,
                    'expires_at' => $now->addSeconds($ttlSeconds),
                    'meta' => [
                        'source_scopes' => $currentSlot->sourceScopes,
                        'source_window_ids' => $currentSlot->sourceWindowIds,
                    ],
                ]);

                if ($resourceSnapshot !== [] && $host instanceof SchedulingHost) {
                    $this->resourceOccupancy->createForHold(
                        hold: $hold,
                        host: $host,
                        snapshot: $resourceSnapshot,
                    );
                }

                $offer->forceFill([
                    'consumed_at' => $now,
                ])->save();

                return $hold->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = BookingHold::query()
                ->with('bookableSlotOffer')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing instanceof BookingHold) {
                throw $exception;
            }

            if ($existing->bookableSlotOffer?->offer_id !== $offerId) {
                throw new LogicException(
                    'The booking hold idempotency key was already used for another slot offer.',
                    previous: $exception,
                );
            }

            $this->assertRequestedLocationMatches($existing, $location);

            return $existing;
        }
    }

    private function assertRequestedLocationMatches(
        BookingHold $hold,
        ?SchedulingLocationSnapshot $requested,
    ): void {
        if ($requested === null) {
            return;
        }

        if (! $hold->locationSnapshot()?->hasSameCommitmentIdentity($requested)) {
            throw new LogicException(
                'The booking hold idempotency key was already used with another location snapshot.',
            );
        }
    }

    private function lockedTarget(
        BookableSlotOffer $offer,
        BookableService $service,
    ): ?SchedulingHost {
        if ($offer->scheduling_host_id === null) {
            $hasAssignments = BookableServiceHost::query()
                ->where('bookable_service_id', $service->getKey())
                ->lockForUpdate()
                ->exists();

            if ($hasAssignments) {
                throw new DomainException(
                    'The selected service now requires an assigned host.',
                );
            }

            return null;
        }

        $host = SchedulingHost::withTrashed()
            ->whereKey($offer->scheduling_host_id)
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

        $assignment = BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('scheduling_host_id', $host->getKey())
            ->lockForUpdate()
            ->first();

        if (! $assignment instanceof BookableServiceHost || ! $assignment->is_active) {
            throw new DomainException(
                'The selected scheduling host is no longer assigned to this service.',
            );
        }

        return $host;
    }

    private function lockedRescheduleAppointment(
        BookableSlotOffer $offer,
        BookableService $service,
    ): ?Appointment {
        if ($offer->reschedule_appointment_id === null) {
            return null;
        }

        $appointment = Appointment::withTrashed()
            ->whereKey($offer->reschedule_appointment_id)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof Appointment || $appointment->trashed()) {
            throw new DomainException(
                'The appointment selected for rescheduling could not be found.',
            );
        }

        if ((int) $appointment->bookable_service_id !== (int) $service->getKey()) {
            throw new DomainException(
                'The appointment selected for rescheduling belongs to another service.',
            );
        }

        if (! in_array($appointment->status, self::RESCHEDULABLE_STATUSES, true)) {
            throw new DomainException(
                "Appointment status [{$appointment->status}] cannot be rescheduled.",
            );
        }

        if (Appointment::withTrashed()
            ->where('rescheduled_from_id', $appointment->getKey())
            ->exists()
        ) {
            throw new DomainException(
                'The appointment has already been rescheduled.',
            );
        }

        return $appointment;
    }

    private function exactCurrentSlot(
        AvailabilitySearch $search,
        BookableSlotOffer $offer,
    ): ?BookableSlot {
        foreach ($this->findAvailability->handle($search) as $slot) {
            if ($slot->bookableServiceId === (int) $offer->bookable_service_id
                && $slot->schedulingHostId === $offer->scheduling_host_id
                && $slot->startsAt->equalTo($offer->starts_at)
                && $slot->endsAt->equalTo($offer->ends_at)
            ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param Collection<int, Appointment> $appointments
     */
    private function lockAppointments(Collection $appointments): void
    {
        $ids = $appointments->modelKeys();

        if ($ids === []) {
            return;
        }

        Appointment::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, BookingHold> $holds
     */
    private function lockHolds(Collection $holds): void
    {
        $ids = $holds->modelKeys();

        if ($ids === []) {
            return;
        }

        BookingHold::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint')
            || str_contains(strtolower($exception->getMessage()), 'duplicate entry');
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("A non-empty {$label} is required.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "The {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}