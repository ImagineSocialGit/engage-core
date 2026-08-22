<?php

namespace App\Modules\Relationships\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use App\Modules\Relationships\Services\RelationshipWorkspaceResolver;
use Illuminate\Support\Collection;

class ContactRelationshipsShowDataProvider implements ContactShowDataProvider
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
        private readonly RelationshipWorkspaceResolver $workspaces,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $definitions = $this->definitions->visible();

        if ($definitions === []) {
            return [
                'contactBusinessContext' => [
                    'primary' => null,
                    'relationships' => [],
                ],
            ];
        }

        $relationships = ContactRelationship::query()
            ->active()
            ->where('contact_id', $contact->getKey())
            ->whereIn('relationship_key', array_keys($definitions))
            ->get()
            ->sortBy(fn (ContactRelationship $relationship): array => [
                $definitions[$relationship->relationship_key]['sort_order'] ?? PHP_INT_MAX,
                $relationship->relationship_key,
            ])
            ->values();

        $items = $relationships
            ->map(fn (ContactRelationship $relationship): array => $this->present(
                relationship: $relationship,
                definition: $definitions[$relationship->relationship_key],
            ));

        $primary = $this->primary($items);

        return [
            'contactBusinessContext' => [
                'primary' => $primary,
                'relationships' => $items->all(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function present(
        ContactRelationship $relationship,
        array $definition,
    ): array {
        $stages = collect($definition['stages'] ?? [])->values();
        $stage = collect($definition['stages'] ?? [])->get($relationship->stage_key);

        return [
            'id' => $relationship->getKey(),
            'key' => $relationship->relationship_key,
            'label' => $definition['singular'],
            'stage_key' => $relationship->stage_key,
            'stage_label' => is_array($stage)
                ? ($stage['label'] ?? null)
                : null,
            'stage_options' => $stages
                ->map(fn (array $stage): array => [
                    'key' => $stage['key'],
                    'label' => $stage['label'],
                    'active' => $stage['active'],
                ])
                ->all(),
            'progression_mode' => $stages->isNotEmpty()
                ? 'relationship_stage'
                : 'contact_status',
            'source' => $relationship->source,
            'subsource' => $relationship->subsource,
            'started_at' => $relationship->started_at,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function primary(Collection $items): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }

        $defaultRelationshipKey = $this->workspaces->defaultRelationshipKey();

        if ($defaultRelationshipKey !== null) {
            $preferred = $items->first(
                fn (array $item): bool => $item['key'] === $defaultRelationshipKey,
            );

            if (is_array($preferred)) {
                return $preferred;
            }
        }

        $first = $items->first();

        return is_array($first) ? $first : null;
    }
}