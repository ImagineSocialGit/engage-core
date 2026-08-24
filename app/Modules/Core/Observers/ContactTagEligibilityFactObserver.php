<?php

namespace App\Modules\Core\Observers;

use App\Modules\Core\Actions\Contacts\RecordContactFilterFactsChangedAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;

final class ContactTagEligibilityFactObserver
{
    public function __construct(
        private readonly RecordContactFilterFactsChangedAction $recordFactsChanged,
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