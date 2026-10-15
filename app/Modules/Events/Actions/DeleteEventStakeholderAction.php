<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStakeholder;
use Illuminate\Support\Facades\DB;

final class DeleteEventStakeholderAction
{
    public function handle(
        Event $event,
        EventStakeholder $stakeholder,
    ): void {
        DB::transaction(function () use ($event, $stakeholder): void {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());
            $currentStakeholder = EventStakeholder::query()
                ->lockForUpdate()
                ->findOrFail($stakeholder->getKey());

            if ((int) $currentStakeholder->event_id !== (int) $currentEvent->getKey()) {
                throw new EventActionBlockedException(
                    blocker: 'stakeholder_event_mismatch',
                );
            }

            $currentStakeholder->delete();
        }, 3);
    }
}