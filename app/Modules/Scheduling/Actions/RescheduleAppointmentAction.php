<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentRescheduleData;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class RescheduleAppointmentAction
{
    private const RESCHEDULABLE_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly TransitionAppointmentStatusAction $lifecycle,
    ) {}

    public function handle(AppointmentRescheduleData $data): Appointment
    {
        $result = DB::transaction(function () use ($data): Appointment|BookingHold {
            $snapshot = BookingHold::query()
                ->where('hold_id', $data->holdId)
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

            $this->assertStableHoldTarget($snapshot, $hold);
            $this->assertOfferMatchesHold($offer, $hold);

            if (! $offer->isRescheduleOffer()) {
                throw new DomainException(
                    'An ordinary booking hold cannot be used to reschedule an appointment.',
                );
            }

            if ($hold->isConverted()) {
                return $this->convertedReplacement($hold, $offer);
            }

            if ($hold->isReleased()) {
                throw new DomainException(
                    'A released booking hold cannot reschedule an appointment.',
                );
            }

            if ($hold->isExpired()) {
                throw new DomainException(
                    'An expired booking hold cannot reschedule an appointment.',
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
                    'The held service is no longer available for rescheduling.',
                );
            }

            if ($hold->scheduling_host_id !== null && ! $host instanceof SchedulingHost) {
                throw new DomainException(
                    'The held scheduling host is no longer available.',
                );
            }

            $original = Appointment::withTrashed()
                ->whereKey($offer->reschedule_appointment_id)
                ->lockForUpdate()
                ->first();

            if (! $original instanceof Appointment || $original->trashed()) {
                throw new DomainException(
                    'The appointment selected for rescheduling could not be found.',
                );
            }

            if ((int) $original->bookable_service_id !== (int) $service->getKey()) {
                throw new DomainException(
                    'The appointment selected for rescheduling belongs to another service.',
                );
            }

            if (! in_array($original->status, self::RESCHEDULABLE_STATUSES, true)) {
                throw new DomainException(
                    "Appointment status [{$original->status}] cannot be rescheduled.",
                );
            }

            if ($this->existingReplacement($original) instanceof Appointment) {
                throw new DomainException(
                    'The appointment has already been rescheduled.',
                );
            }

            $this->assertRescheduleNotice(
                appointment: $original,
                service: $service,
                occurredAt: $data->lifecycle->occurredAt,
                force: $data->lifecycle->force,
            );

            $attendees = AppointmentAttendee::query()
                ->where('appointment_id', $original->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $originalStatus = (string) $original->status;
            $preserveConfirmation = $service->requires_confirmation
                && $data->preserveConfirmation
                && $originalStatus === Appointment::STATUS_CONFIRMED;
            $replacementStatus = $this->replacementStatus(
                service: $service,
                preserveConfirmation: $preserveConfirmation,
            );
            $replacement = Appointment::query()->create([
                'bookable_service_id' => $service->getKey(),
                'scheduling_host_id' => $host?->getKey(),
                'contact_id' => $original->contact_id,
                'location_reference_type' => $original->location_reference_type,
                'location_reference_id' => $original->location_reference_id,
                'primary_attendee_type' => $original->primary_attendee_type,
                'primary_attendee_id' => $original->primary_attendee_id,
                'source_context_type' => $original->source_context_type,
                'source_context_id' => $original->source_context_id,
                'rescheduled_from_id' => $original->getKey(),
                'status' => $replacementStatus,
                'title' => $original->title,
                'description' => $original->description,
                'location_type' => $original->location_type,
                'location_details' => $original->location_details,
                'timezone' => $original->timezone,
                'starts_at' => $hold->starts_at,
                'ends_at' => $hold->ends_at,
                'confirmed_at' => $replacementStatus === Appointment::STATUS_CONFIRMED
                    ? ($original->confirmed_at ?? $data->lifecycle->occurredAt)
                    : null,
                'source' => $data->lifecycle->source,
                'created_by_type' => $data->lifecycle->actor?->getMorphClass()
                    ?? $original->created_by_type,
                'created_by_id' => $data->lifecycle->actor?->getKey()
                    ?? $original->created_by_id,
                'meta' => array_replace_recursive(
                    is_array($original->meta) ? $original->meta : [],
                    [
                        'rescheduling' => array_filter([
                            'from_appointment_id' => (int) $original->getKey(),
                            'booking_hold_id' => $hold->hold_id,
                            'slot_offer_id' => $offer->offer_id,
                            'display_timezone' => $offer->display_timezone,
                            'preserved_confirmation' => $preserveConfirmation,
                        ], static fn (mixed $value): bool => $value !== null),
                    ],
                ),
            ]);

            $this->copyAttendees(
                attendees: $attendees,
                replacement: $replacement,
                service: $service,
                preserveConfirmation: $preserveConfirmation,
                occurredAt: $data->lifecycle->occurredAt,
            );

            $canceledAttendeeCount = $this->cancelOriginalAttendees(
                appointment: $original,
                occurredAt: $data->lifecycle->occurredAt,
            );
            $cancellationReason = $data->lifecycle->reason ?? 'rescheduled';

            $original->forceFill([
                'status' => Appointment::STATUS_CANCELED,
                'canceled_at' => $data->lifecycle->occurredAt,
                'cancellation_reason' => $cancellationReason,
            ])->save();

            $this->lifecycle->recordRescheduled(
                replacement: $replacement,
                original: $original,
                originalStatus: $originalStatus,
                context: $data->lifecycle,
                additionalContext: [
                    'booking_hold_id' => $hold->hold_id,
                    'slot_offer_id' => $offer->offer_id,
                    'preserved_confirmation' => $preserveConfirmation,
                    'canceled_attendee_count' => $canceledAttendeeCount,
                ],
            );

            $hold->forceFill([
                'appointment_id' => $replacement->getKey(),
                'status' => BookingHold::STATUS_CONVERTED,
                'converted_at' => $now,
            ])->save();

            return $replacement->refresh();
        });

        if ($result instanceof BookingHold) {
            throw new DomainException(
                'The booking hold expired before the appointment could be rescheduled.',
            );
        }

        return $result;
    }

    private function assertStableHoldTarget(
        BookingHold $snapshot,
        BookingHold $hold,
    ): void {
        if ((int) $hold->bookable_slot_offer_id !== (int) $snapshot->bookable_slot_offer_id
            || (int) $hold->bookable_service_id !== (int) $snapshot->bookable_service_id
            || ! $this->sameHost($hold->scheduling_host_id, $snapshot->scheduling_host_id)
        ) {
            throw new LogicException(
                'The booking hold target changed while it was being converted.',
            );
        }
    }

    private function assertOfferMatchesHold(
        BookableSlotOffer $offer,
        BookingHold $hold,
    ): void {
        if ((int) $offer->bookable_service_id !== (int) $hold->bookable_service_id
            || ! $this->sameHost($offer->scheduling_host_id, $hold->scheduling_host_id)
            || ! $offer->starts_at->equalTo($hold->starts_at)
            || ! $offer->ends_at->equalTo($hold->ends_at)
        ) {
            throw new LogicException(
                'The booking hold no longer matches its slot offer.',
            );
        }
    }

    private function convertedReplacement(
        BookingHold $hold,
        BookableSlotOffer $offer,
    ): Appointment {
        $appointment = Appointment::withTrashed()
            ->whereKey($hold->appointment_id)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof Appointment || $appointment->trashed()) {
            throw new LogicException(
                'The converted booking hold no longer references an appointment.',
            );
        }

        if ((int) $appointment->rescheduled_from_id
            !== (int) $offer->reschedule_appointment_id
        ) {
            throw new LogicException(
                'The converted booking hold does not reference its reschedule replacement.',
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

    private function existingReplacement(Appointment $original): ?Appointment
    {
        return Appointment::withTrashed()
            ->where('rescheduled_from_id', $original->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function assertRescheduleNotice(
        Appointment $appointment,
        BookableService $service,
        CarbonImmutable $occurredAt,
        bool $force,
    ): void {
        if ($force) {
            return;
        }

        $startsAt = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->utc()
            : null;

        if ($startsAt === null) {
            throw new LogicException(
                'Appointment rescheduling requires starts_at.',
            );
        }

        $noticeMinutes = max(0, (int) $service->reschedule_notice_minutes);
        $deadline = $startsAt->subMinutes($noticeMinutes);

        if ($occurredAt->greaterThan($deadline)) {
            throw new DomainException(sprintf(
                'The appointment reschedule notice window requires at least %d minute(s).',
                $noticeMinutes,
            ));
        }
    }

    private function replacementStatus(
        BookableService $service,
        bool $preserveConfirmation,
    ): string {
        if (! $service->requires_confirmation) {
            return Appointment::STATUS_SCHEDULED;
        }

        return $preserveConfirmation
            ? Appointment::STATUS_CONFIRMED
            : Appointment::STATUS_PENDING;
    }

    /**
     * @param Collection<int, AppointmentAttendee> $attendees
     */
    private function copyAttendees(
        Collection $attendees,
        Appointment $replacement,
        BookableService $service,
        bool $preserveConfirmation,
        CarbonImmutable $occurredAt,
    ): void {
        foreach ($attendees as $attendee) {
            $isPrimary = $attendee->role === 'primary';
            $status = $attendee->status;
            $respondedAt = $attendee->responded_at;
            $canceledAt = $status === AppointmentAttendee::STATUS_CANCELED
                ? $attendee->canceled_at
                : null;

            if ($isPrimary) {
                if ($service->requires_confirmation && ! $preserveConfirmation) {
                    $status = AppointmentAttendee::STATUS_INVITED;
                    $respondedAt = null;
                    $canceledAt = null;
                } else {
                    $status = AppointmentAttendee::STATUS_ACCEPTED;
                    $respondedAt = $preserveConfirmation
                        ? ($attendee->responded_at ?? $occurredAt)
                        : $occurredAt;
                    $canceledAt = null;
                }
            }

            AppointmentAttendee::query()->create([
                'appointment_id' => $replacement->getKey(),
                'attendee_type' => $attendee->attendee_type,
                'attendee_id' => $attendee->attendee_id,
                'contact_id' => $attendee->contact_id,
                'name' => $attendee->name,
                'email' => $attendee->email,
                'phone' => $attendee->phone,
                'role' => $attendee->role,
                'status' => $status,
                'responded_at' => $respondedAt,
                'joined_at' => null,
                'canceled_at' => $canceledAt,
                'meta' => array_replace_recursive(
                    is_array($attendee->meta) ? $attendee->meta : [],
                    [
                        'rescheduling' => [
                            'from_appointment_attendee_id' => (int) $attendee->getKey(),
                        ],
                    ],
                ),
            ]);
        }
    }

    private function cancelOriginalAttendees(
        Appointment $appointment,
        CarbonImmutable $occurredAt,
    ): int {
        return AppointmentAttendee::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereIn('status', [
                AppointmentAttendee::STATUS_INVITED,
                AppointmentAttendee::STATUS_ACCEPTED,
                AppointmentAttendee::STATUS_TENTATIVE,
            ])
            ->update([
                'status' => AppointmentAttendee::STATUS_CANCELED,
                'canceled_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }
}