<?php

namespace App\Modules\Relationships\Import\Treatments;

use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Validation\ValidationException;

final class RelationshipTypeImportTreatmentTarget implements ContactImportTreatmentTarget
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $relationships,
    ) {}

    public function available(): bool
    {
        return $this->relationships->visible() !== [];
    }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'relationship_type',
            label: 'Relationship',
            section: 'Relationships',
            description: 'Apply a configured relationship to every imported Contact, or map source values to relationship types.',
            options: collect($this->relationships->visible())
                ->map(fn (array $definition): array => [
                    'value' => $definition['key'],
                    'label' => $definition['singular'],
                ])
                ->values()
                ->all(),
            sort: 10,
        );
    }

    public function normalizeValues(array $values): array
    {
        $values = collect($values)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values();

        if ($values->count() > 1) {
            throw ValidationException::withMessages([
                'treatments.relationship_type' => 'Relationship accepts one destination relationship per treatment value.',
            ]);
        }

        if ($values->isEmpty()) {
            return [];
        }

        $relationshipKey = $values->first();
        $visible = $this->relationships->visible();

        if (! isset($visible[$relationshipKey])) {
            throw ValidationException::withMessages([
                'treatments.relationship_type' => "Unknown or hidden relationship [{$relationshipKey}].",
            ]);
        }

        return [$relationshipKey];
    }

    public function fieldOverrides(array $values): array
    {
        $relationshipKey = $values[0] ?? null;

        return $relationshipKey !== null
            ? ['relationship_key' => $relationshipKey]
            : [];
    }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        // Relationship persistence remains owned by ContactRelationshipImportHandler.
    }
}