<?php

namespace App\Modules\Relationships\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Database\Eloquent\Builder;

class RelationshipContactFilterCriterion implements ContactFilterCriterion
{
    public const NO_RELATIONSHIP = '__no_relationship__';
    public const ANY_RELATIONSHIP = '__any_relationship__';

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
        $options = [
            ['value' => self::NO_RELATIONSHIP, 'label' => 'No relationship'],
            ['value' => self::ANY_RELATIONSHIP, 'label' => 'Any relationship'],
        ];

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
        $withoutRelationship = in_array(self::NO_RELATIONSHIP, $values, true);
        $withAnyRelationship = in_array(self::ANY_RELATIONSHIP, $values, true);

        if ($withoutRelationship && $withAnyRelationship) {
            return;
        }

        if ($withAnyRelationship) {
            $query->whereExists($this->activeRelationships(...));

            return;
        }

        $wildcards = [];
        $stages = [];

        foreach ($values as $value) {
            if ($value === self::NO_RELATIONSHIP) {
                continue;
            }

            [$relationshipKey, $stageKey] = array_pad(explode(':', $value, 2), 2, '*');

            if ($stageKey === '*') {
                $wildcards[] = $relationshipKey;
                continue;
            }

            $stages[$relationshipKey][] = $stageKey;
        }

        if ($withoutRelationship && $wildcards === [] && $stages === []) {
            $query->whereNotExists($this->activeRelationships(...));

            return;
        }

        $applySelectedRelationships = function (Builder $query) use ($wildcards, $stages): void {
            $query->whereExists(function ($subquery) use ($wildcards, $stages): void {
                $this->activeRelationships($subquery)
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
        };

        if ($withoutRelationship) {
            $query->where(function (Builder $query) use ($applySelectedRelationships): void {
                $query->whereNotExists($this->activeRelationships(...));
                $query->orWhere(function (Builder $query) use ($applySelectedRelationships): void {
                    $applySelectedRelationships($query);
                });
            });

            return;
        }

        $applySelectedRelationships($query);
    }

    private function activeRelationships($query)
    {
        return $query
            ->selectRaw('1')
            ->from('contact_relationships')
            ->whereColumn('contact_relationships.contact_id', 'contacts.id')
            ->where('contact_relationships.is_active', true);
    }
}