<?php

namespace App\Modules\Relationships\Import\Treatments;

use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Validation\ValidationException;

final class RelationshipStageImportTreatmentTarget implements ContactImportTreatmentTarget
{
    private const SEPARATOR = '::';

    public function __construct(
        private readonly RelationshipDefinitionRegistry $relationships,
    ) {}

    public function available(): bool
    {
        return $this->stageOptions() !== [];
    }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'relationship_stage',
            label: 'Relationship Stage',
            section: 'Relationships',
            description: 'Choose a relationship-specific stage. Each destination also establishes the matching relationship type for that row.',
            options: $this->stageOptions(),
            sort: 20,
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
                'treatments.relationship_stage' => 'Relationship Stage accepts one destination stage per treatment value.',
            ]);
        }

        if ($values->isEmpty()) {
            return [];
        }

        $value = $values->first();
        [$relationshipKey, $stageKey] = $this->split($value);
        $definition = $this->relationships->visible()[$relationshipKey] ?? null;
        $stage = is_array($definition)
            ? ($definition['stages'][$stageKey] ?? null)
            : null;

        if (! is_array($stage) || ! $stage['active']) {
            throw ValidationException::withMessages([
                'treatments.relationship_stage' => "Unknown or inactive relationship stage [{$value}].",
            ]);
        }

        return [$value];
    }

    public function fieldOverrides(array $values): array
    {
        $value = $values[0] ?? null;

        if ($value === null) {
            return [];
        }

        [$relationshipKey, $stageKey] = $this->split($value);

        return [
            'relationship_key' => $relationshipKey,
            'relationship_stage' => $stageKey,
        ];
    }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        // Relationship persistence remains owned by ContactRelationshipImportHandler.
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function stageOptions(): array
    {
        $options = [];

        foreach ($this->relationships->visible() as $relationship) {
            foreach ($relationship['stages'] as $stage) {
                if (! $stage['active']) {
                    continue;
                }

                $options[] = [
                    'value' => $relationship['key'].self::SEPARATOR.$stage['key'],
                    'label' => $relationship['singular'].' — '.$stage['label'],
                ];
            }
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $value): array
    {
        $parts = explode(self::SEPARATOR, $value, 2);

        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            throw ValidationException::withMessages([
                'treatments.relationship_stage' => "Invalid relationship stage treatment value [{$value}].",
            ]);
        }

        return [trim($parts[0]), trim($parts[1])];
    }
}