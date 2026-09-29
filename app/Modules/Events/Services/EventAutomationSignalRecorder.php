<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;

final class EventAutomationSignalRecorder
{
    public const CREATED = 'event.created';

    public const UPCOMING = 'event.upcoming';

    public const ANNOUNCEMENT_REACHED = 'event.announcement_reached';

    public const POSTPONED = 'event.postponed';

    public const RESCHEDULED = 'event.rescheduled';

    public const CANCELLED = 'event.cancelled';

    public const COMPLETED = 'event.completed';

    public const ATTENDANCE_RECORDED = 'event.attendance_recorded';

    public function __construct(
        private readonly AutomationEventOutbox $outbox,
    ) {}

    public function recordCreated(
        Event $event,
        EventActionContext $context,
    ): void {
        $this->recordEvent(
            eventKey: self::CREATED,
            event: $event,
            context: $context,
            payload: [
                'from_status' => null,
                'to_status' => EventStatus::Draft->value,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordLifecycle(
        string $eventKey,
        Event $event,
        EventStatus $fromStatus,
        EventStatus $toStatus,
        EventActionContext $context,
        array $payload = [],
    ): void {
        $this->recordEvent(
            eventKey: $eventKey,
            event: $event,
            context: $context,
            payload: [
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                ...$payload,
            ],
        );
    }

    public function recordAnnouncementReached(
        Event $event,
        EventActionContext $context,
    ): bool {
        $outboxEvent = $this->outbox->record(
            AutomationEventData::forSubject(
                eventKey: self::ANNOUNCEMENT_REACHED,
                subject: $event,
                occurredAt: $context->occurredAtValue(),
                payload: [
                    'event_id' => (int) $event->getKey(),
                    'status' => $event->status->value,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'timezone' => $event->timezone,
                    'announcement_at' => $event->announcement_at?->toISOString(),
                ],
                meta: $this->meta($context),
            ),
            idempotencyKey: $this->announcementReachedIdempotencyKey($event),
        );

        return $outboxEvent->wasRecentlyCreated;
    }

    /**
     * @return array<int, int>
     */
    public function recordedAnnouncementEventIds(): array
    {
        $subjectType = (new Event())->getMorphClass();

        return AutomationEventOutboxEvent::query()
            ->where('event_key', self::ANNOUNCEMENT_REACHED)
            ->where('subject_type', $subjectType)
            ->whereNotNull('subject_id')
            ->pluck('subject_id')
            ->map(static function (mixed $subjectId): ?int {
                $value = is_int($subjectId)
                    ? (string) $subjectId
                    : (is_string($subjectId) ? trim($subjectId) : '');

                return $value !== '' && ctype_digit($value)
                    ? (int) $value
                    : null;
            })
            ->filter(static fn (?int $eventId): bool => $eventId !== null)
            ->unique()
            ->values()
            ->all();
    }

    public function recordAttendance(
        EventAttendance $attendance,
        EventActionContext $context,
    ): void {
        $this->outbox->record(AutomationEventData::forSubject(
            eventKey: self::ATTENDANCE_RECORDED,
            subject: $attendance,
            contactId: (int) $attendance->contact_id,
            occurredAt: $context->occurredAtValue(),
            payload: [
                'event_attendance_id' => (int) $attendance->getKey(),
                'event_id' => (int) $attendance->event_id,
                'contact_id' => (int) $attendance->contact_id,
                'status' => $attendance->status->value,
                'observed_at' => $attendance->observed_at?->toISOString(),
                'source_key' => $attendance->source_key,
                'source_reference' => $attendance->source_reference,
            ],
            meta: $this->meta($context),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        string $eventKey,
        Event $event,
        EventActionContext $context,
        array $payload = [],
    ): void {
        $this->outbox->record(AutomationEventData::forSubject(
            eventKey: $eventKey,
            subject: $event,
            occurredAt: $context->occurredAtValue(),
            payload: [
                'event_id' => (int) $event->getKey(),
                'status' => $event->status->value,
                'starts_at' => $event->starts_at?->toISOString(),
                'ends_at' => $event->ends_at?->toISOString(),
                'timezone' => $event->timezone,
                ...$payload,
            ],
            meta: $this->meta($context),
        ));
    }

    private function announcementReachedIdempotencyKey(Event $event): string
    {
        return 'events:event:'.(int) $event->getKey().':announcement_reached';
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(EventActionContext $context): array
    {
        $meta = [
            'source_module' => 'events',
            'source' => $context->sourceKey(),
        ];

        if ($context->reasonValue() !== null) {
            $meta['reason'] = $context->reasonValue();
        }

        return $meta + $context->meta;
    }
}