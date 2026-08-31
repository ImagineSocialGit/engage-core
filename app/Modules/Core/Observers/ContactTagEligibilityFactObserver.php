<?php

namespace App\Modules\Core\Observers;

use App\Modules\Core\Actions\Contacts\RecordContactFilterFactsChangedAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;

final class ContactTagEligibilityFactObserver
{
    public function __construct(
        private readonly RecordContactFilterFactsChangedAction $recordFactsChanged,
        private readonly AutomationEventOutbox $automationEvents,
    ) {}

    public function created(ContactTag $contactTag): void
    {
        $this->record(
            contactTag: $contactTag,
            source: 'core.contact_tag_created',
            changes: [
                'added' => [(string) $contactTag->tag],
            ],
        );

        $this->recordTagAddedEvent($contactTag, 'created');
    }

    public function updated(ContactTag $contactTag): void
    {
        if (! $contactTag->wasChanged('tag')) {
            return;
        }

        $this->record(
            contactTag: $contactTag,
            source: 'core.contact_tag_updated',
            changes: [
                'removed' => [(string) $contactTag->getOriginal('tag')],
                'added' => [(string) $contactTag->tag],
            ],
        );

        $this->recordTagAddedEvent($contactTag, 'updated');
    }

    private function recordTagAddedEvent(ContactTag $contactTag, string $operation): void
    {
        $this->automationEvents->record(
            AutomationEventData::forSubject(
                eventKey: 'contact.tag_added',
                subject: $contactTag,
                contactId: (int) $contactTag->contact_id,
                payload: [
                    'contact_tag' => [
                        'id' => (int) $contactTag->getKey(),
                        'tag' => (string) $contactTag->tag,
                    ],
                ],
                meta: [
                    'source_module' => 'core',
                    'operation' => $operation,
                ],
            ),
        );
    }

    public function deleted(ContactTag $contactTag): void
    {
        $this->record(
            contactTag: $contactTag,
            source: 'core.contact_tag_deleted',
            changes: [
                'removed' => [(string) $contactTag->tag],
            ],
        );
    }

    /** @param array<string, mixed> $changes */
    private function record(
        ContactTag $contactTag,
        string $source,
        array $changes,
    ): void {
        $contact = Contact::query()->find($contactTag->contact_id);

        if (! $contact instanceof Contact) {
            return;
        }

        $this->recordFactsChanged->handle(
            contact: $contact,
            criterionKeys: ['tag'],
            source: $source,
            changes: $changes,
            subject: $contact,
        );
    }
}