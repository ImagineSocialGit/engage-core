<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ConvertBookingHoldToAppointmentAction
{
    public function __construct(
        private readonly TransitionAppointmentStatusAction $lifecycle,
    ) {}

    public function handle(
        string $holdId,
        AppointmentBookingData $booking,
    ): Appointment {
        $holdId = $this->requiredHoldId($holdId);

        $result = DB::transaction(function () use ($holdId, $booking): Appointment|BookingHold {
            $snapshot = BookingHold::query()
                ->where('hold_id', $holdId)
                ->first([
                    'id',
                    'bookable_slot_offer_id',
                    'bookable_service_id',
                    'scheduling_host_id',
                ]);

            if (! $snapshot instanceof BookingHold) {
                throw new DomainException('The booking hold could not be found.');
            }

            $offer = BookableSlotOffer::query()
                ->whereKey($snapshot->bookable_slot_offer_id)
                ->lockForUpdate()
                ->first();

            if (! $offer instanceof BookableSlotOffer) {
                throw new LogicException(
                    'The booking hold no longer references a slot offer.',
                );
            }

            $service = BookableService::withTrashed()
                ->whereKey($snapshot->bookable_service_id)
                ->lockForUpdate()
                ->first();
            $host = $this->lockedHost($snapshot->scheduling_host_id);
            $hold = BookingHold::query()
                ->whereKey($snapshot->getKey())
                ->lockForUpdate()
                ->first();

            if (! $hold instanceof BookingHold) {
                throw new DomainException('The booking hold could not be found.');
            }

            if ((int) $hold->bookable_slot_offer_id !== (int) $snapshot->bookable_slot_offer_id
                || (int) $hold->bookable_service_id !== (int) $snapshot->bookable_service_id
                || ! $this->sameHost($hold->scheduling_host_id, $snapshot->scheduling_host_id)
            ) {
                throw new LogicException(
                    'The booking hold target changed while it was being converted.',
                );
            }

            if ($offer->isRescheduleOffer()) {
                throw new DomainException(
                    'A reschedule-scoped booking hold must be converted through RescheduleAppointmentAction.',
                );
            }

            if ($hold->isConverted()) {
                return $this->convertedAppointment($hold);
            }

            if ($hold->isReleased()) {
                throw new DomainException(
                    'A released booking hold cannot be converted to an appointment.',
                );
            }

            if ($hold->isExpired()) {
                throw new DomainException(
                    'An expired booking hold cannot be converted to an appointment.',
                );
            }

            $now = CarbonImmutable::now('UTC');

            if (! $hold->isEffectivelyActive($now)) {
                $hold->forceFill([
                    'status' => BookingHold::STATUS_EXPIRED,
                ])->save();

                return $hold->refresh();
            }

            if (! $service instanceof BookableService
                || $service->trashed()
                || $service->status !== BookableService::STATUS_ACTIVE
            ) {
                throw new DomainException(
                    'The held service is no longer available for booking.',
                );
            }

            if ($hold->scheduling_host_id !== null && ! $host instanceof SchedulingHost) {
                throw new DomainException(
                    'The held scheduling host is no longer available.',
                );
            }

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
                'status' => $status,
                'title' => $booking->title ?? $service->name,
                'description' => $booking->description,
                'location_type' => $service->location_type,
                'location_details' => $service->location_details,
                'timezone' => $service->timezone,
                'starts_at' => $hold->starts_at,
                'ends_at' => $hold->ends_at,
                'source' => $booking->source,
                'created_by_type' => $booking->createdBy?->getMorphClass(),
                'created_by_id' => $booking->createdBy?->getKey(),
                'meta' => array_replace_recursive(
                    $booking->appointmentMeta,
                    [
                        'booking' => array_filter([
                            'hold_id' => $hold->hold_id,
                            'slot_offer_id' => $offer->offer_id,
                            'display_timezone' => $offer->display_timezone,
                        ], static fn (mixed $value): bool => $value !== null),
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
                'responded_at' => $service->requires_confirmation ? null : $now,
                'meta' => array_replace_recursive(
                    $booking->attendeeMeta,
                    [
                        'booking' => [
                            'hold_id' => $hold->hold_id,
                        ],
                    ],
                ),
            ]);

            $this->lifecycle->recordInitial(
                appointment: $appointment,
                context: new AppointmentLifecycleContext(
                    actor: $booking->createdBy,
                    source: $booking->source,
                    reason: 'booking_hold_converted',
                    occurredAt: $now,
                    context: array_filter([
                        'booking_hold_id' => $hold->hold_id,
                        'slot_offer_id' => $offer->offer_id,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
            );

            $hold->forceFill([
                'appointment_id' => $appointment->getKey(),
                'status' => BookingHold::STATUS_CONVERTED,
                'converted_at' => $now,
            ])->save();

            return $appointment->refresh();
        });

        if ($result instanceof BookingHold) {
            throw new DomainException(
                'The booking hold expired before it could be converted to an appointment.',
            );
        }

        return $result;
    }

    private function convertedAppointment(BookingHold $hold): Appointment
    {
        $appointment = Appointment::withTrashed()
            ->whereKey($hold->appointment_id)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof Appointment) {
            throw new LogicException(
                'The converted booking hold no longer references an appointment.',
            );
        }

        return $appointment;
    }

    private function lockedHost(mixed $hostId): ?SchedulingHost
    {
        if ($hostId === null) {
            return null;
        }

        $host = SchedulingHost::withTrashed()
            ->whereKey($hostId)
            ->lockForUpdate()
            ->first();

        if (! $host instanceof SchedulingHost
            || $host->trashed()
            || $host->status !== SchedulingHost::STATUS_ACTIVE
        ) {
            return null;
        }

        return $host;
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }

    private function requiredHoldId(string $holdId): string
    {
        $holdId = trim($holdId);

        if ($holdId === '') {
            throw new InvalidArgumentException(
                'A non-empty booking hold ID is required.',
            );
        }

        if (mb_strlen($holdId) > 36) {
            throw new InvalidArgumentException(
                'The booking hold ID cannot exceed 36 characters.',
            );
        }

        return $holdId;
    }
}