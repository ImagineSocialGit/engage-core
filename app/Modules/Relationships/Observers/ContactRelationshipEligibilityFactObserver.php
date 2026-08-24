<?php

namespace App\Modules\Relationships\Observers;

use App\Modules\Core\Actions\Contacts\RecordContactFilterFactsChangedAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;

final class ContactRelationshipEligibilityFactObserver
{
    private const ELIGIBILITY_ATTRIBUTES = [
        'contact_id',
        'relationship_key',
        'stage_key',
        'is_active',
    ];

    public function __construct(
        private readonly RecordContactFilterFactsChangedAction $recordFactsChanged,
    ) {}

    public function created(ContactRelationship $relationship): void
    {
        $this->record(
            relationship: $relationship,
            source: 'relationships.relationship_created',
            changes: [
                'relationship_key' => $relationship->relationship_key,
                'stage_key' => $relationship->stage_key,
                'is_active' => (bool) $relationship->is_active,
            ],
        );
    }

    public function updated(ContactRelationship $relationship): void
    {
        if (! $this->eligibilityFactsChanged($relationship)) {
            return;
        }

        $changes = [];

        foreach (self::ELIGIBILITY_ATTRIBUTES as $attribute) {
            if (! $relationship->wasChanged($attribute)) {
                continue;
            }

            $changes[$attribute] = [
                'from' => $relationship->getOriginal($attribute),
                'to' => $relationship->getAttribute($attribute),
            ];
        }

        $this->record(
            relationship: $relationship,
            source: 'relationships.relationship_updated',
            changes: $changes,
        );
    }

    public function deleted(ContactRelationship $relationship): void
    {
        $this->record(
            relationship: $relationship,
            source: 'relationships.relationship_deleted',
            changes: [
                'relationship_key' => $relationship->relationship_key,
                'stage_key' => $relationship->stage_key,
                'is_active' => false,
            ],
        );
    }

    private function eligibilityFactsChanged(ContactRelationship $relationship): bool
    {
        foreach (self::ELIGIBILITY_ATTRIBUTES as $attribute) {
            if ($relationship->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $changes */
    private function record(
        ContactRelationship $relationship,
        string $source,
        array $changes,
    ): void {
        $contact = Contact::query()->find($relationship->contact_id);

        if (! $contact instanceof Contact) {
            return;
        }

        $this->recordFactsChanged->handle(
            contact: $contact,
            criterionKeys: ['relationship'],
            source: $source,
            changes: $changes,
            subject: $contact,
            meta: [
                'source_module' => 'relationships',
            ],
        );
    }
}