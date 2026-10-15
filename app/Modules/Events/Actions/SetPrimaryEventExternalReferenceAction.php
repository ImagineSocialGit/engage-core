<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use Illuminate\Support\Facades\DB;

final class SetPrimaryEventExternalReferenceAction
{
    public function handle(
        Event $event,
        ?EventExternalReference $reference,
    ): Event {
        return DB::transaction(function () use ($event, $reference): Event {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            $referenceId = null;

            if ($reference instanceof EventExternalReference) {
                $currentReference = EventExternalReference::query()
                    ->lockForUpdate()
                    ->findOrFail($reference->getKey());

                if ((int) $currentReference->event_id !== (int) $currentEvent->getKey()) {
                    throw new EventActionBlockedException(
                        blocker: 'external_reference_event_mismatch',
                    );
                }

                $referenceId = (int) $currentReference->getKey();
            }

            if ($currentEvent->primary_external_reference_id === $referenceId) {
                return $currentEvent;
            }

            $currentEvent->primary_external_reference_id = $referenceId;
            $currentEvent->save();

            return $currentEvent->refresh();
        }, 3);
    }
}