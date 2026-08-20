<?php

namespace App\Modules\Core\Import\Treatments;

use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Validation\ValidationException;

final class ContactTagsImportTreatmentTarget implements ContactImportTreatmentTarget
{
    private const MAX_TAGS_PER_VALUE = 25;
    private const MAX_TAG_LENGTH = 255;

    public function available(): bool
    {
        return true;
    }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'contact_tags',
            label: 'Tags',
            section: 'Contact',
            description: 'Add one or more tags to every imported Contact, or map CSV values to additive tags. Existing tags are never removed.',
            multiple: true,
            allowCustom: true,
            options: ContactTag::query()
                ->select('tag')
                ->distinct()
                ->orderBy('tag')
                ->limit(200)
                ->pluck('tag')
                ->map(fn (string $tag): array => [
                    'value' => $tag,
                    'label' => $tag,
                ])
                ->all(),
            sort: 20,
        );
    }

    public function normalizeValues(array $values): array
    {
        $tags = collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($tags->count() > self::MAX_TAGS_PER_VALUE) {
            throw ValidationException::withMessages([
                'treatments.contact_tags' => 'A single import treatment value may add at most '.self::MAX_TAGS_PER_VALUE.' tags.',
            ]);
        }

        foreach ($tags as $tag) {
            if (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                throw ValidationException::withMessages([
                    'treatments.contact_tags' => 'Contact tags may not exceed '.self::MAX_TAG_LENGTH.' characters.',
                ]);
            }
        }

        return $tags->all();
    }

    public function fieldOverrides(array $values): array
    {
        return [];
    }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        foreach ($application->values as $tag) {
            ContactTag::query()->firstOrCreate([
                'contact_id' => $application->contact->id,
                'tag' => $tag,
            ]);
        }
    }
}