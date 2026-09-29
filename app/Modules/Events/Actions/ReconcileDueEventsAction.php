<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReconcileDueEventsAction
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly CompleteEventAction $completeEvent,
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    /**
     * @return array{completed: int, announcements_recorded: int}
     */
    public function handle(?CarbonInterface $now = null): array
    {
        $now = $now === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($now)->utc();

        return [
            'completed' => $this->completeDueEvents($now),
            'announcements_recorded' => $this->recordDueAnnouncements($now),
        ];
    }

    private function completeDueEvents(CarbonImmutable $now): int
    {
        $completed = 0;

        Event::query()
            ->where('status', EventStatus::Upcoming->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(
                self::CHUNK_SIZE,
                function (Collection $events) use ($now, &$completed): void {
                    foreach ($events as $event) {
                        if ($this->completeIfStillDue($event, $now)) {
                            $completed++;
                        }
                    }
                },
            );

        return $completed;
    }

    private function completeIfStillDue(Event $event, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($event, $now): bool {
            $current = Event::query()
                ->lockForUpdate()
                ->find($event->getKey());

            if (! $current instanceof Event
                || $current->status !== EventStatus::Upcoming
                || $current->ends_at === null
                || $current->ends_at->gt($now)
            ) {
                return false;
            }

            $this->completeEvent->handle(
                event: $current,
                context: new EventActionContext(
                    source: 'scheduled_reconciliation',
                    occurredAt: $now,
                    meta: [
                        'automatic' => true,
                        'trigger' => 'ends_at_elapsed',
                    ],
                ),
            );

            return true;
        }, 3);
    }

    private function recordDueAnnouncements(CarbonImmutable $now): int
    {
        $recorded = 0;
        $alreadyRecordedEventIds = $this->signals->recordedAnnouncementEventIds();

        Event::query()
            ->whereIn('status', [
                EventStatus::Draft->value,
                EventStatus::Upcoming->value,
                EventStatus::Postponed->value,
            ])
            ->whereNotNull('announcement_at')
            ->where('announcement_at', '<=', $now)
            ->when(
                $alreadyRecordedEventIds !== [],
                fn ($query) => $query->whereNotIn('id', $alreadyRecordedEventIds),
            )
            ->orderBy('id')
            ->chunkById(
                self::CHUNK_SIZE,
                function (Collection $events) use ($now, &$recorded): void {
                    foreach ($events as $event) {
                        if ($this->recordAnnouncementIfStillDue($event, $now)) {
                            $recorded++;
                        }
                    }
                },
            );

        return $recorded;
    }

    private function recordAnnouncementIfStillDue(
        Event $event,
        CarbonImmutable $now,
    ): bool {
        return DB::transaction(function () use ($event, $now): bool {
            $current = Event::query()
                ->lockForUpdate()
                ->find($event->getKey());

            if (! $current instanceof Event
                || ! in_array($current->status, [
                    EventStatus::Draft,
                    EventStatus::Upcoming,
                    EventStatus::Postponed,
                ], true)
                || $current->announcement_at === null
                || $current->announcement_at->gt($now)
            ) {
                return false;
            }

            return $this->signals->recordAnnouncementReached(
                event: $current,
                context: new EventActionContext(
                    source: 'scheduled_reconciliation',
                    occurredAt: $current->announcement_at,
                    meta: [
                        'automatic' => true,
                        'trigger' => 'announcement_at_reached',
                    ],
                ),
            );
        }, 3);
    }
}