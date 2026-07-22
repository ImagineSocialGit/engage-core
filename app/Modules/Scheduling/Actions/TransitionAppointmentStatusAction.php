<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class TransitionAppointmentStatusAction
{
    private const TARGET_EVENT_KEYS = [
        Appointment::STATUS_CONFIRMED => AppointmentLifecycleEvent::EVENT_CONFIRMED,
        Appointment::STATUS_CANCELED => AppointmentLifecycleEvent::EVENT_CANCELED,
        Appointment::STATUS_COMPLETED => AppointmentLifecycleEvent::EVENT_COMPLETED,
        Appointment::STATUS_NO_SHOW => AppointmentLifecycleEvent::EVENT_NO_SHOW,
    ];

    private const ALLOWED_FROM = [
        Appointment::STATUS_CONFIRMED => [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_SCHEDULED,
        ],
        Appointment::STATUS_CANCELED => [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_SCHEDULED,
            Appointment::STATUS_CONFIRMED,
        ],
        Appointment::STATUS_COMPLETED => [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_SCHEDULED,
            Appointment::STATUS_CONFIRMED,
        ],
        Appointment::STATUS_NO_SHOW => [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_SCHEDULED,
            Appointment::STATUS_CONFIRMED,
        ],
    ];

    public function __construct(
        private readonly AutomationEventOutbox $automationEvents,
    ) {}

    public function handle(
        Appointment $appointment,
        string $toStatus,
        ?AppointmentLifecycleContext $context = null,
    ): Appointment {
        $appointmentId = $this->requiredAppointmentId($appointment);
        $toStatus = $this->normalizedTargetStatus($toStatus);
        $context ??= new AppointmentLifecycleContext();

        return DB::transaction(function () use (
            $appointmentId,
            $toStatus,
            $context,
        ): Appointment {
            [$locked, $service] = $this->lockedAppointmentAndService($appointmentId);
            $fromStatus = (string) $locked->status;

            if ($fromStatus === $toStatus) {
                if ($toStatus === Appointment::STATUS_CONFIRMED) {
                    $this->confirmAttendee($locked, $context);
                }

                return $locked->refresh();
            }

            $this->assertTransitionAllowed($fromStatus, $toStatus);
            $this->assertTiming($locked, $service, $toStatus, $context);

            $additionalContext = [];

            if ($toStatus === Appointment::STATUS_CONFIRMED) {
                $attendee = $this->confirmAttendee($locked, $context);

                if ($attendee !== null) {
                    $additionalContext['appointment_attendee_id'] = (int) $attendee->getKey();
                }
            }

            if ($toStatus === Appointment::STATUS_CANCELED) {
                $additionalContext['canceled_attendee_count'] = $this->cancelAttendees(
                    appointment: $locked,
                    occurredAt: $context->occurredAt,
                );
            }

            $locked->forceFill($this->statusAttributes(
                toStatus: $toStatus,
                context: $context,
            ))->save();

            $this->recordLifecycleAndAutomationEvent(
                appointment: $locked,
                eventKey: self::TARGET_EVENT_KEYS[$toStatus],
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                context: $context,
                additionalContext: $additionalContext,
            );

            return $locked->refresh();
        });
    }

    public function recordInitial(
        Appointment $appointment,
        ?AppointmentLifecycleContext $context = null,
    ): Appointment {
        $appointmentId = $this->requiredAppointmentId($appointment);
        $context ??= new AppointmentLifecycleContext();

        return DB::transaction(function () use ($appointmentId, $context): Appointment {
            [$locked] = $this->lockedAppointmentAndService($appointmentId);
            $eventKey = match ($locked->status) {
                Appointment::STATUS_PENDING => AppointmentLifecycleEvent::EVENT_CREATED,
                Appointment::STATUS_SCHEDULED => AppointmentLifecycleEvent::EVENT_SCHEDULED,
                default => throw new LogicException(
                    "Initial appointment lifecycle recording does not support status [{$locked->status}].",
                ),
            };

            $existing = AppointmentLifecycleEvent::query()
                ->where('appointment_id', $locked->getKey())
                ->where('event_key', $eventKey)
                ->whereNull('from_status')
                ->where('to_status', $locked->status)
                ->orderBy('id')
                ->first();

            if (! $existing instanceof AppointmentLifecycleEvent) {
                $this->recordLifecycleAndAutomationEvent(
                    appointment: $locked,
                    eventKey: $eventKey,
                    fromStatus: null,
                    toStatus: (string) $locked->status,
                    context: $context,
                );
            }

            return $locked->refresh();
        });
    }

    /**
     * @return array{0: Appointment, 1: BookableService}
     */
    private function lockedAppointmentAndService(int $appointmentId): array
    {
        $snapshot = Appointment::withTrashed()
            ->whereKey($appointmentId)
            ->first(['id', 'bookable_service_id']);

        if (! $snapshot instanceof Appointment) {
            throw new DomainException('The appointment could not be found.');
        }

        $service = BookableService::withTrashed()
            ->whereKey($snapshot->bookable_service_id)
            ->lockForUpdate()
            ->first();

        if (! $service instanceof BookableService) {
            throw new LogicException(
                'The appointment no longer references a bookable service.',
            );
        }

        $appointment = Appointment::withTrashed()
            ->whereKey($appointmentId)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof Appointment || $appointment->trashed()) {
            throw new DomainException('The appointment could not be found.');
        }

        if ((int) $appointment->bookable_service_id !== (int) $service->getKey()) {
            throw new LogicException(
                'The appointment service changed while its lifecycle was being updated.',
            );
        }

        return [$appointment, $service];
    }

    private function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if (! in_array($fromStatus, self::ALLOWED_FROM[$toStatus], true)) {
            throw new DomainException(
                "Appointment status [{$fromStatus}] cannot transition to [{$toStatus}].",
            );
        }
    }

    private function assertTiming(
        Appointment $appointment,
        BookableService $service,
        string $toStatus,
        AppointmentLifecycleContext $context,
    ): void {
        $startsAt = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->utc()
            : null;

        if ($startsAt === null) {
            throw new LogicException('Appointment lifecycle transitions require starts_at.');
        }

        if (in_array($toStatus, [
            Appointment::STATUS_COMPLETED,
            Appointment::STATUS_NO_SHOW,
        ], true) && $context->occurredAt->lessThan($startsAt)) {
            throw new DomainException(
                "Appointment status [{$toStatus}] cannot be recorded before the appointment starts.",
            );
        }

        if ($toStatus !== Appointment::STATUS_CANCELED || $context->force) {
            return;
        }

        $noticeMinutes = max(0, (int) $service->cancellation_notice_minutes);
        $cancellationDeadline = $startsAt->subMinutes($noticeMinutes);

        if ($context->occurredAt->greaterThan($cancellationDeadline)) {
            throw new DomainException(sprintf(
                'The appointment cancellation notice window requires at least %d minute(s).',
                $noticeMinutes,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function statusAttributes(
        string $toStatus,
        AppointmentLifecycleContext $context,
    ): array {
        return match ($toStatus) {
            Appointment::STATUS_CONFIRMED => [
                'status' => $toStatus,
                'confirmed_at' => $context->occurredAt,
            ],
            Appointment::STATUS_CANCELED => [
                'status' => $toStatus,
                'canceled_at' => $context->occurredAt,
                'cancellation_reason' => $context->reason,
            ],
            Appointment::STATUS_COMPLETED => [
                'status' => $toStatus,
                'completed_at' => $context->occurredAt,
            ],
            Appointment::STATUS_NO_SHOW => [
                'status' => $toStatus,
                'no_show_at' => $context->occurredAt,
            ],
            default => throw new LogicException(
                "Unsupported appointment target status [{$toStatus}].",
            ),
        };
    }

    private function confirmAttendee(
        Appointment $appointment,
        AppointmentLifecycleContext $context,
    ): ?AppointmentAttendee {
        $attendee = $context->attendee !== null
            ? AppointmentAttendee::query()
                ->whereKey($context->attendee->getKey())
                ->lockForUpdate()
                ->first()
            : AppointmentAttendee::query()
                ->where('appointment_id', $appointment->getKey())
                ->where('role', 'primary')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

        if ($context->attendee !== null
            && (! $attendee instanceof AppointmentAttendee
                || (int) $attendee->appointment_id !== (int) $appointment->getKey())
        ) {
            throw new DomainException(
                'The confirming attendee does not belong to this appointment.',
            );
        }

        if (! $attendee instanceof AppointmentAttendee) {
            return null;
        }

        if (! in_array($attendee->status, [
            AppointmentAttendee::STATUS_INVITED,
            AppointmentAttendee::STATUS_TENTATIVE,
            AppointmentAttendee::STATUS_ACCEPTED,
        ], true)) {
            throw new DomainException(
                "Appointment attendee status [{$attendee->status}] cannot be confirmed.",
            );
        }

        $attributes = [];

        if ($attendee->status !== AppointmentAttendee::STATUS_ACCEPTED) {
            $attributes['status'] = AppointmentAttendee::STATUS_ACCEPTED;
        }

        if ($attendee->responded_at === null) {
            $attributes['responded_at'] = $context->occurredAt;
        }

        if ($attributes !== []) {
            $attendee->forceFill($attributes)->save();
        }

        return $attendee->refresh();
    }

    private function cancelAttendees(
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

    /**
     * @param array<string, mixed> $additionalContext
     */
    private function recordLifecycleAndAutomationEvent(
        Appointment $appointment,
        string $eventKey,
        ?string $fromStatus,
        string $toStatus,
        AppointmentLifecycleContext $context,
        array $additionalContext = [],
    ): AppointmentLifecycleEvent {
        $eventContext = array_replace(
            $context->context,
            $additionalContext,
        );

        $lifecycleEvent = AppointmentLifecycleEvent::query()->create([
            'appointment_id' => $appointment->getKey(),
            'event_key' => $eventKey,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $context->actor?->getMorphClass(),
            'actor_id' => $context->actor?->getKey(),
            'source' => $context->source,
            'reason' => $context->reason,
            'context' => $eventContext,
            'occurred_at' => $context->occurredAt,
        ]);

        $this->automationEvents->record(
            AutomationEventData::forSubject(
                eventKey: 'appointment.'.$eventKey,
                subject: $appointment,
                contactId: $appointment->contact_id,
                occurredAt: $context->occurredAt,
                payload: $this->automationPayload(
                    appointment: $appointment,
                    fromStatus: $fromStatus,
                    toStatus: $toStatus,
                    eventContext: $eventContext,
                ),
                meta: [
                    'source' => $context->source,
                    'reason' => $context->reason,
                    'actor_type' => $context->actor?->getMorphClass(),
                    'actor_id' => $context->actor?->getKey(),
                    'force' => $context->force,
                ],
                eventId: $lifecycleEvent->event_id,
            ),
            idempotencyKey: implode(':', [
                'scheduling',
                'appointment',
                $appointment->getKey(),
                'lifecycle',
                $lifecycleEvent->event_id,
            ]),
        );

        return $lifecycleEvent;
    }

    /**
     * @param array<string, mixed> $eventContext
     * @return array<string, mixed>
     */
    private function automationPayload(
        Appointment $appointment,
        ?string $fromStatus,
        string $toStatus,
        array $eventContext,
    ): array {
        return [
            'appointment_id' => (int) $appointment->getKey(),
            'bookable_service_id' => (int) $appointment->bookable_service_id,
            'scheduling_host_id' => $appointment->scheduling_host_id !== null
                ? (int) $appointment->scheduling_host_id
                : null,
            'contact_id' => $appointment->contact_id !== null
                ? (int) $appointment->contact_id
                : null,
            'primary_attendee_type' => $appointment->primary_attendee_type,
            'primary_attendee_id' => $appointment->primary_attendee_id !== null
                ? (int) $appointment->primary_attendee_id
                : null,
            'appointment_attendee_id' => isset($eventContext['appointment_attendee_id'])
                ? (int) $eventContext['appointment_attendee_id']
                : null,
            'from_status' => $fromStatus,
            'status' => $toStatus,
            'starts_at' => $appointment->starts_at?->toISOString(),
            'ends_at' => $appointment->ends_at?->toISOString(),
            'timezone' => $appointment->timezone,
        ];
    }

    private function requiredAppointmentId(Appointment $appointment): int
    {
        if (! $appointment->exists || $appointment->getKey() === null) {
            throw new InvalidArgumentException(
                'Appointment lifecycle actions require a persisted Appointment.',
            );
        }

        return (int) $appointment->getKey();
    }

    private function normalizedTargetStatus(string $status): string
    {
        $status = str_replace('-', '_', strtolower(trim($status)));

        if (! array_key_exists($status, self::TARGET_EVENT_KEYS)) {
            throw new InvalidArgumentException(
                "Unsupported appointment lifecycle target status [{$status}].",
            );
        }

        return $status;
    }
}