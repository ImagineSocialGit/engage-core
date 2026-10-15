<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use Illuminate\Support\Facades\DB;

final class DeleteEventExternalReferenceAction
{
    public function handle(
        Event $event,
        EventExternalReference $reference,
    ): Event {
        return DB::transaction(function () use ($event, $reference): Event {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());
            $currentReference = EventExternalReference::query()
                ->lockForUpdate()
                ->findOrFail($reference->getKey());

            if ((int) $currentReference->event_id !== (int) $currentEvent->getKey()) {
                throw new EventActionBlockedException(
                    blocker: 'external_reference_event_mismatch',
                );
            }

            if ((int) $currentEvent->primary_external_reference_id === (int) $currentReference->getKey()) {
                $currentEvent->primary_external_reference_id = null;
                $currentEvent->save();
            }

            $currentReference->delete();

            return $currentEvent->refresh();
        }, 3);
    }
}