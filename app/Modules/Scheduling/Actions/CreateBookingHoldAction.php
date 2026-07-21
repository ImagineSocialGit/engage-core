<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilityInterval;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\AppointmentOccupancyResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class CreateBookingHoldAction
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly AppointmentOccupancyResolver $appointmentOccupancy,
    ) {}

    public function handle(
        string $offerId,
        string $idempotencyKey,
    ): BookingHold {
        $offerId = $this->requiredString($offerId, 'offer ID', 36);
        $idempotencyKey = $this->requiredString(
            $idempotencyKey,
            'booking hold idempotency key',
            191,
        );

        try {
            return DB::transaction(function () use ($offerId, $idempotencyKey): BookingHold {
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

                [$host, $assignment] = $this->lockedTarget($offer, $service);
                $search = new AvailabilitySearch(
                    service: $service,
                    startsAt: $offer->starts_at,
                    endsAt: $offer->ends_at,
                    host: $host,
                    displayTimezone: $offer->display_timezone,
                    evaluatedAt: $now,
                );

                $appointments = $this->appointmentOccupancy
                    ->blockingAppointments($search, $host);

                $this->lockAppointments($appointments);

                $currentSlot = $this->exactCurrentSlot($search, $offer);

                if (! $currentSlot instanceof BookableSlot) {
                    throw new DomainException('The selected slot is no longer available.');
                }

                $occupancyStartsAt = $currentSlot->startsAt->subMinutes(
                    max(0, (int) $service->buffer_before_minutes),
                );
                $occupancyEndsAt = $currentSlot->endsAt->addMinutes(
                    max(0, (int) $service->buffer_after_minutes),
                );

                $holds = $this->lockedOverlappingHolds(
                    service: $service,
                    host: $host,
                    startsAt: $occupancyStartsAt,
                    endsAt: $occupancyEndsAt,
                    now: $now,
                );

                $sameServiceHostHolds = $holds
                    ->filter(fn (BookingHold $hold): bool =>
                        (int) $hold->bookable_service_id === (int) $service->getKey()
                        && $this->sameHost($hold->scheduling_host_id, $host?->getKey())
                    )
                    ->count();

                if ($sameServiceHostHolds >= $currentSlot->remainingCapacity) {
                    throw new DomainException('The selected slot no longer has available capacity.');
                }

                if ($host !== null) {
                    $hostRemainingAfterAppointments = $this->hostRemainingAfterAppointments(
                        service: $service,
                        host: $host,
                        startsAt: $currentSlot->startsAt,
                        endsAt: $currentSlot->endsAt,
                        appointments: $appointments,
                    );

                    if ($holds->count() >= $hostRemainingAfterAppointments) {
                        throw new DomainException('The selected host no longer has available capacity.');
                    }
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
                    'held_at' => $now,
                    'expires_at' => $now->addSeconds($ttlSeconds),
                    'meta' => [
                        'source_scopes' => $currentSlot->sourceScopes,
                        'source_window_ids' => $currentSlot->sourceWindowIds,
                    ],
                ]);

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

            return $existing;
        }
    }

    /**
     * @return array{0: SchedulingHost|null, 1: BookableServiceHost|null}
     */
    private function lockedTarget(
        BookableSlotOffer $offer,
        BookableService $service,
    ): array {
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

            return [null, null];
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

        return [$host, $assignment];
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
     * @return Collection<int, BookingHold>
     */
    private function lockedOverlappingHolds(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $now,
    ): Collection {
        $query = BookingHold::query()
            ->effectivelyActive($now)
            ->overlappingOccupancy($startsAt, $endsAt);

        if ($host !== null) {
            $query->where('scheduling_host_id', $host->getKey());
        } else {
            $query
                ->where('bookable_service_id', $service->getKey())
                ->whereNull('scheduling_host_id');
        }

        return $query
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, Appointment> $appointments
     */
    private function hostRemainingAfterAppointments(
        BookableService $service,
        SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        Collection $appointments,
    ): int {
        $probe = clone $service;
        $probe->capacity = PHP_INT_MAX;

        return $this->appointmentOccupancy->remainingCapacity(
            service: $probe,
            host: $host,
            assignment: null,
            availability: new AvailabilityInterval(
                startsAt: $startsAt,
                endsAt: $endsAt,
                hostId: (int) $host->getKey(),
            ),
            startsAt: $startsAt,
            endsAt: $endsAt,
            appointments: $appointments,
        );
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
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