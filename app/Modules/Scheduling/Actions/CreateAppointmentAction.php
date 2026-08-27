<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\BookingOccupancyResolver;
use App\Modules\Scheduling\Services\Availability\ResourceOccupancyResolver;
use App\Modules\Scheduling\Services\SchedulingDurationResolver;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateAppointmentAction
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly BookingOccupancyResolver $occupancy,
        private readonly ResourceOccupancyResolver $resourceOccupancy,
        private readonly TransitionAppointmentStatusAction $lifecycle,
        private readonly SchedulingLocationSnapshotResolver $locations,
        private readonly SchedulingDurationResolver $durations,
    ) {}

    public function handle(AppointmentCreationData $data): Appointment
    {
        $existing = $this->existingAppointment($data->idempotencyKey);

        if ($existing instanceof Appointment) {
            return $this->matchingExistingAppointment($existing, $data);
        }

        try {
            return DB::transaction(function () use ($data): Appointment {
                $service = BookableService::withTrashed()
                    ->whereKey($data->service->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $service instanceof BookableService
                    || $service->trashed()
                    || $service->status !== BookableService::STATUS_ACTIVE
                ) {
                    throw new DomainException(
                        'The selected service is no longer available for appointment creation.',
                    );
                }

                $existing = $this->existingAppointment($data->idempotencyKey);

                if ($existing instanceof Appointment) {
                    return $this->matchingExistingAppointment($existing, $data);
                }

                [$host] = $this->lockedTarget(
                    service: $service,
                    requestedHost: $data->host,
                );
                $locationSnapshot = $this->locations->forCommitment(
                    service: $service,
                    requested: $data->booking->location,
                );
                $resourceSnapshot = $this->resourceOccupancy->lockRequirementSnapshot(
                    service: $service,
                    host: $host,
                );
                $evaluatedAt = CarbonImmutable::now('UTC');
                $endsAt = $this->durations->resolveEndsAt(
                    service: $service,
                    startsAt: $data->startsAt,
                    requestedEndsAt: $data->endsAt,
                    requireExplicitRange: $service->usesRangeDuration(),
                );
                $candidateDurationMinutes = $this->durations->durationMinutes(
                    service: $service,
                    startsAt: $data->startsAt,
                    endsAt: $endsAt,
                );
                $search = new AvailabilitySearch(
                    service: $service,
                    startsAt: $data->startsAt,
                    endsAt: $endsAt,
                    host: $host,
                    displayTimezone: $service->timezone,
                    evaluatedAt: $evaluatedAt,
                    location: $locationSnapshot,
                    candidateDurationMinutes: $candidateDurationMinutes,
                );

                $appointments = $this->occupancy
                    ->blockingAppointments($search, $host);
                $this->lockAppointments($appointments);

                $holds = $this->occupancy
                    ->activeHolds($search, $host);
                $this->lockHolds($holds);

                $slot = $this->exactCurrentSlot(
                    search: $search,
                    service: $service,
                    host: $host,
                );

                if (! $slot instanceof BookableSlot) {
                    throw new DomainException(
                        'The selected appointment time is no longer available.',
                    );
                }

                $booking = $data->booking;
                $status = $service->requires_confirmation
                    ? Appointment::STATUS_PENDING
                    : Appointment::STATUS_SCHEDULED;
                $attendeeStatus = $service->requires_confirmation
                    ? AppointmentAttendee::STATUS_INVITED
                    : AppointmentAttendee::STATUS_ACCEPTED;
                $primaryAttendee = $booking->primaryAttendee();

                $appointment = Appointment::query()->create([
                    'bookable_service_id' => $service->getKey(),
                    'scheduling_host_id' => $host?->getKey(),
                    'contact_id' => $booking->contact?->getKey(),
                    'primary_attendee_type' => $primaryAttendee?->getMorphClass(),
                    'primary_attendee_id' => $primaryAttendee?->getKey(),
                    'source_context_type' => $booking->sourceContext?->getMorphClass(),
                    'source_context_id' => $booking->sourceContext?->getKey(),
                    'idempotency_key' => $data->idempotencyKey,
                    'status' => $status,
                    'title' => $booking->title ?? $service->name,
                    'description' => $booking->description,
                    'location_type' => $locationSnapshot?->type,
                    'location_details' => $locationSnapshot?->details,
                    'timezone' => $service->timezone,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'source' => $booking->source,
                    'created_by_type' => $booking->createdBy?->getMorphClass(),
                    'created_by_id' => $booking->createdBy?->getKey(),
                    'meta' => array_replace_recursive(
                        $booking->appointmentMeta,
                        [
                            'creation' => [
                                'mode' => 'direct',
                                'display_timezone' => $slot->displayTimezone,
                            ],
                        ],
                    ),
                ]);

                AppointmentAttendee::query()->create([
                    'appointment_id' => $appointment->getKey(),
                    'attendee_type' => $primaryAttendee?->getMorphClass(),
                    'attendee_id' => $primaryAttendee?->getKey(),
                    'contact_id' => $booking->contact?->getKey(),
                    'name' => $booking->attendeeName(),
                    'email' => $booking->attendeeEmail(),
                    'phone' => $booking->attendeePhone(),
                    'role' => 'primary',
                    'status' => $attendeeStatus,
                    'responded_at' => $service->requires_confirmation
                        ? null
                        : $data->lifecycle->occurredAt,
                    'meta' => array_replace_recursive(
                        $booking->attendeeMeta,
                        [
                            'creation' => [
                                'mode' => 'direct',
                            ],
                        ],
                    ),
                ]);

                if ($resourceSnapshot !== [] && $host instanceof SchedulingHost) {
                    $this->resourceOccupancy->createForAppointment(
                        appointment: $appointment,
                        service: $service,
                        host: $host,
                        snapshot: $resourceSnapshot,
                    );
                }

                $this->lifecycle->recordInitial(
                    appointment: $appointment,
                    context: $data->lifecycle,
                );

                return $appointment->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->existingAppointment($data->idempotencyKey);

            if (! $existing instanceof Appointment) {
                throw $exception;
            }

            return $this->matchingExistingAppointment($existing, $data);
        }
    }

    /**
     * @return array{0: SchedulingHost|null, 1: BookableServiceHost|null}
     */
    private function lockedTarget(
        BookableService $service,
        ?SchedulingHost $requestedHost,
    ): array {
        if ($requestedHost === null) {
            $assignments = BookableServiceHost::query()
                ->where('bookable_service_id', $service->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assignments->isNotEmpty()) {
                throw new DomainException(
                    'The selected service requires an explicit assigned scheduling host.',
                );
            }

            return [null, null];
        }

        $host = SchedulingHost::withTrashed()
            ->whereKey($requestedHost->getKey())
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
                'The selected scheduling host is not actively assigned to this service.',
            );
        }

        return [$host, $assignment];
    }

    private function exactCurrentSlot(
        AvailabilitySearch $search,
        BookableService $service,
        ?SchedulingHost $host,
    ): ?BookableSlot {
        foreach ($this->findAvailability->handle($search) as $slot) {
            if ($slot->bookableServiceId === (int) $service->getKey()
                && $slot->schedulingHostId === $host?->getKey()
                && $slot->startsAt->equalTo($search->requestedStartsAt)
                && $slot->endsAt->equalTo($search->requestedEndsAt)
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

    private function existingAppointment(string $idempotencyKey): ?Appointment
    {
        return Appointment::withTrashed()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function matchingExistingAppointment(
        Appointment $appointment,
        AppointmentCreationData $data,
    ): Appointment {
        $primaryAttendee = $data->booking->primaryAttendee();
        $expectedEndsAt = $this->durations->resolveEndsAt(
            service: $data->service,
            startsAt: $data->startsAt,
            requestedEndsAt: $data->endsAt,
            requireExplicitRange: $data->service->usesRangeDuration(),
        );

        if ((int) $appointment->bookable_service_id !== (int) $data->service->getKey()
            || ! $this->sameHost(
                $appointment->scheduling_host_id,
                $data->host?->getKey(),
            )
            || ! $appointment->starts_at?->equalTo($data->startsAt)
            || ! $appointment->ends_at?->equalTo($expectedEndsAt)
            || ! $this->sameNullableInteger(
                $appointment->contact_id,
                $data->booking->contact?->getKey(),
            )
            || ! $this->sameMorphIdentity(
                type: $appointment->primary_attendee_type,
                id: $appointment->primary_attendee_id,
                expectedType: $primaryAttendee?->getMorphClass(),
                expectedId: $primaryAttendee?->getKey(),
            )
            || ($primaryAttendee === null
                && $data->booking->contact === null
                && ! $this->sameSnapshotAttendee($appointment, $data))
            || ($data->booking->location !== null
                && ! $appointment->locationSnapshot()?->hasSameCommitmentIdentity($data->booking->location))
        ) {
            throw new LogicException(
                'The appointment idempotency key was already used for another creation request.',
            );
        }

        return $appointment;
    }


    private function sameSnapshotAttendee(
        Appointment $appointment,
        AppointmentCreationData $data,
    ): bool {
        $attendee = AppointmentAttendee::withTrashed()
            ->where('appointment_id', $appointment->getKey())
            ->where('role', 'primary')
            ->orderBy('id')
            ->first();

        if (! $attendee instanceof AppointmentAttendee) {
            return false;
        }

        return $this->sameNullableString($attendee->name, $data->booking->attendeeName())
            && $this->sameNullableString($attendee->email, $data->booking->attendeeEmail(), true)
            && $this->sameNullableString($attendee->phone, $data->booking->attendeePhone());
    }

    private function sameNullableString(
        mixed $left,
        ?string $right,
        bool $caseInsensitive = false,
    ): bool {
        $left = is_string($left) && trim($left) !== '' ? trim($left) : null;
        $right = is_string($right) && trim($right) !== '' ? trim($right) : null;

        if ($caseInsensitive) {
            $left = $left !== null ? mb_strtolower($left) : null;
            $right = $right !== null ? mb_strtolower($right) : null;
        }

        return $left === $right;
    }

    private function sameMorphIdentity(
        mixed $type,
        mixed $id,
        ?string $expectedType,
        mixed $expectedId,
    ): bool {
        return $type === $expectedType
            && $this->sameNullableInteger($id, $expectedId);
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        return $this->sameNullableInteger($left, $right);
    }

    private function sameNullableInteger(mixed $left, mixed $right): bool
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
}