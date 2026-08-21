<?php

namespace App\Modules\Relationships\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Database\Eloquent\Builder;

class RelationshipContactFilterCriterion implements ContactFilterCriterion
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
    ) {}

    public function key(): string
    {
        return 'relationship';
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function label(): string
    {
        return 'Relationship';
    }

    public function help(): ?string
    {
        return 'Target a relationship population, optionally narrowed to a stage.';
    }

    public function options(): array
    {
        $options = [];

        foreach ($this->definitions->visible() as $relationship) {
            $options[] = [
                'value' => $relationship['key'].':*',
                'label' => $relationship['singular'].' — any stage',
            ];

            foreach ($relationship['stages'] as $stage) {
                if (! $stage['active']) {
                    continue;
                }

                $options[] = [
                    'value' => $relationship['key'].':'.$stage['key'],
                    'label' => $relationship['singular'].' — '.$stage['label'],
                ];
            }
        }

        return $options;
    }

    public function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $allowed = array_column($this->options(), 'value');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && in_array(trim($value), $allowed, true)
                ? trim($value)
                : null,
            $values,
        ))));
    }

    public function apply(Builder $query, array $values): void
    {
        $wildcards = [];
        $stages = [];

        foreach ($values as $value) {
            [$relationshipKey, $stageKey] = array_pad(explode(':', $value, 2), 2, '*');

            if ($stageKey === '*') {
                $wildcards[] = $relationshipKey;
                continue;
            }

            $stages[$relationshipKey][] = $stageKey;
        }

        $query->whereExists(function ($subquery) use ($wildcards, $stages): void {
            $subquery
                ->selectRaw('1')
                ->from('contact_relationships')
                ->whereColumn('contact_relationships.contact_id', 'contacts.id')
                ->where('contact_relationships.is_active', true)
                ->where(function ($relationshipQuery) use ($wildcards, $stages): void {
                    $hasClause = false;

                    if ($wildcards !== []) {
                        $relationshipQuery->whereIn('contact_relationships.relationship_key', array_values(array_unique($wildcards)));
                        $hasClause = true;
                    }

                    foreach ($stages as $relationshipKey => $stageKeys) {
                        $method = $hasClause ? 'orWhere' : 'where';

                        $relationshipQuery->{$method}(function ($stageQuery) use ($relationshipKey, $stageKeys): void {
                            $stageQuery
                                ->where('contact_relationships.relationship_key', $relationshipKey)
                                ->whereIn('contact_relationships.stage_key', array_values(array_unique($stageKeys)));
                        });

                        $hasClause = true;
                    }
                });
        });
    }
}