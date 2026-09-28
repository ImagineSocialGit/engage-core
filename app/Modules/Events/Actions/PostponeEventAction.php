<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use Illuminate\Support\Facades\DB;

final class PostponeEventAction
{
    public function __construct(
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    public function handle(
        Event $event,
        EventActionContext $context,
    ): Event {
        return DB::transaction(function () use ($event, $context): Event {
            $current = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            if ($current->status === EventStatus::Postponed) {
                return $current;
            }

            if ($current->status !== EventStatus::Upcoming) {
                throw new EventActionBlockedException(
                    blocker: 'invalid_lifecycle_transition',
                    context: [
                        'from_status' => $current->status->value,
                        'to_status' => EventStatus::Postponed->value,
                    ],
                );
            }

            $fromStatus = $current->status;
            $current->status = EventStatus::Postponed;
            $current->save();

            $this->signals->recordLifecycle(
                eventKey: EventAutomationSignalRecorder::POSTPONED,
                event: $current,
                fromStatus: $fromStatus,
                toStatus: EventStatus::Postponed,
                context: $context,
            );

            return $current->refresh();
        }, 3);
    }
}