<?php

namespace App\Modules\Core\Observers;

use App\Modules\Core\Actions\Contacts\RecordContactFilterFactsChangedAction;
use App\Modules\Core\Models\Contact;

final class ContactEligibilityFactObserver
{
    public function __construct(
        private readonly RecordContactFilterFactsChangedAction $recordFactsChanged,
    ) {}

    public function created(Contact $contact): void
    {
        $criterionKeys = [];
        $changes = [];

        if ($this->hasValue($contact->source)) {
            $criterionKeys[] = 'source';
            $changes['source'] = [
                'from' => null,
                'to' => $contact->source,
            ];
        }

        if ($this->hasValue($contact->subsource)) {
            $criterionKeys[] = 'subsource';
            $changes['subsource'] = [
                'from' => null,
                'to' => $contact->subsource,
            ];
        }

        $this->record(
            contact: $contact,
            criterionKeys: $criterionKeys,
            source: 'core.contact_created',
            changes: $changes,
        );
    }

    public function updated(Contact $contact): void
    {
        $criterionKeys = [];
        $changes = [];

        foreach (['source', 'subsource'] as $criterionKey) {
            if (! $contact->wasChanged($criterionKey)) {
                continue;
            }

            $criterionKeys[] = $criterionKey;
            $changes[$criterionKey] = [
                'from' => $contact->getOriginal($criterionKey),
                'to' => $contact->getAttribute($criterionKey),
            ];
        }

        $this->record(
            contact: $contact,
            criterionKeys: $criterionKeys,
            source: 'core.contact_updated',
            changes: $changes,
        );
    }

    /** @param array<int, string> $criterionKeys @param array<string, mixed> $changes */
    private function record(
        Contact $contact,
        array $criterionKeys,
        string $source,
        array $changes,
    ): void {
        if ($criterionKeys === []) {
            return;
        }

        $this->recordFactsChanged->handle(
            contact: $contact,
            criterionKeys: $criterionKeys,
            source: $source,
            changes: $changes,
        );
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value)
            ? trim($value) !== ''
            : $value !== null;
    }
}