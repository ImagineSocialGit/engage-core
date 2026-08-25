<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Support\Arr;

class FlowRouteEntryCriteriaEvaluator
{
    /**
     * @param array<string, mixed> $executionMeta
     */
    public function matches(FlowRoute $flowRoute, array $executionMeta): bool
    {
        $conditions = data_get($flowRoute->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->matchesCondition($condition, $executionMeta)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $executionMeta
     */
    private function matchesCondition(array $condition, array $executionMeta): bool
    {
        if (($condition['source'] ?? null) !== 'execution_meta') {
            return false;
        }

        $path = $condition['path'] ?? null;
        $operator = $condition['operator'] ?? 'equals';

        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $actual = Arr::get($executionMeta, trim($path));

        return match ($operator) {
            'equals' => $this->normalize($actual) === $this->normalize($condition['value'] ?? null),
            'in' => in_array(
                $this->normalize($actual),
                array_map(
                    fn (mixed $value): mixed => $this->normalize($value),
                    is_array($condition['values'] ?? null) ? $condition['values'] : [],
                ),
                true,
            ),
            default => false,
        };
    }

    private function normalize(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}