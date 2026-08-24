<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

final class RecordContactFilterFactsChangedAction
{
    /**
     * @param array<int, string> $criterionKeys
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $meta
     */
    public function handle(
        Contact $contact,
        array $criterionKeys,
        string $source,
        array $changes = [],
        ?Model $subject = null,
        array $meta = [],
    ): ?ContactFilterFactsChanged {
        $criterionKeys = $this->criterionKeys($criterionKeys);

        if ($criterionKeys === []) {
            return null;
        }

        $source = trim($source);
        $subject ??= $contact;

        $event = new ContactFilterFactsChanged(
            contactId: (int) $contact->getKey(),
            criterionKeys: $criterionKeys,
            source: $source !== '' ? $source : 'contact_filter_fact_change',
            changes: $changes,
            subjectType: $subject->getMorphClass(),
            subjectId: $subject->getKey(),
            meta: array_replace_recursive([
                'source_module' => 'core',
            ], $meta),
        );

        Event::dispatch($event);

        return $event;
    }

    /**
     * Criterion keys are registry identities. Trim them, but never rewrite
     * punctuation or case into a different key.
     *
     * @param array<int, mixed> $criterionKeys
     * @return array<int, string>
     */
    private function criterionKeys(array $criterionKeys): array
    {
        $normalized = [];

        foreach ($criterionKeys as $criterionKey) {
            if (! is_string($criterionKey)) {
                continue;
            }

            $criterionKey = trim($criterionKey);

            if ($criterionKey === '' || in_array($criterionKey, $normalized, true)) {
                continue;
            }

            $normalized[] = $criterionKey;
        }

        return $normalized;
    }
}