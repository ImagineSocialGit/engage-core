<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\ManualContactCreated;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;

final class RecordManualContactCreatedAutomationEvent
{
    public function __construct(
        private readonly AutomationEventOutbox $outbox,
    ) {}

    public function handle(ManualContactCreated $event): void
    {
        $contact = $event->contact;

        $this->outbox->record(
            AutomationEventData::forSubject(
                eventKey: 'contact.created',
                subject: $contact,
                contactId: (int) $contact->getKey(),
                payload: [
                    'contact' => [
                        'id' => (int) $contact->getKey(),
                        'source' => $contact->source,
                        'subsource' => $contact->subsource,
                    ],
                    'creation' => [
                        'kind' => 'manual',
                        'actor_user_id' => $event->actorUserId,
                    ],
                ],
                meta: ['source_module' => 'core'],
            ),
            idempotencyKey: 'contact-created:'.$contact->getKey(),
        );
    }
}