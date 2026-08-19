<?php

namespace App\Modules\Relationships\Services;

use InvalidArgumentException;

class RelationshipDefinitionRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    /**
     * @return array<string, array{
     *     key: string,
     *     singular: string,
     *     plural: string,
     *     visible: bool,
     *     sort_order: int,
     *     stages: array<string, array{key: string, label: string, sort_order: int, active: bool}>
     * }>
     */
    public function all(): array
    {
        $types = config('relationships.types', []);

        if (! is_array($types)) {
            throw new InvalidArgumentException(
                'Relationship types configuration must be an array.',
            );
        }

        $definitions = [];

        foreach ($types as $key => $definition) {
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                throw new InvalidArgumentException(
                    'Relationship type keys must use lowercase snake_case.',
                );
            }

            if (! is_array($definition)) {
                throw new InvalidArgumentException(
                    "Relationship type [{$key}] must be an array.",
                );
            }

            $this->rejectUnknownFields(
                value: $definition,
                allowed: ['singular', 'plural', 'visible', 'sort_order', 'stages'],
                path: "relationships.types.{$key}",
            );

            $singular = $this->requiredLabel(
                $definition['singular'] ?? null,
                "relationships.types.{$key}.singular",
            );
            $plural = $this->requiredLabel(
                $definition['plural'] ?? null,
                "relationships.types.{$key}.plural",
            );

            $definitions[$key] = [
                'key' => $key,
                'singular' => $singular,
                'plural' => $plural,
                'visible' => $this->booleanValue(
                    $definition['visible'] ?? true,
                    "relationships.types.{$key}.visible",
                ),
                'sort_order' => $this->integerValue(
                    $definition['sort_order'] ?? 0,
                    "relationships.types.{$key}.sort_order",
                ),
                'stages' => $this->normalizeStages(
                    relationshipKey: $key,
                    stages: $definition['stages'] ?? [],
                ),
            ];
        }

        uasort(
            $definitions,
            static fn (array $left, array $right): int => [
                $left['sort_order'],
                $left['key'],
            ] <=> [
                $right['sort_order'],
                $right['key'],
            ],
        );

        return $definitions;
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     singular: string,
     *     plural: string,
     *     visible: bool,
     *     sort_order: int,
     *     stages: array<string, array{key: string, label: string, sort_order: int, active: bool}>
     * }>
     */
    public function visible(): array
    {
        return array_filter(
            $this->all(),
            static fn (array $definition): bool => $definition['visible'],
        );
    }

    /**
     * @return array{
     *     key: string,
     *     singular: string,
     *     plural: string,
     *     visible: bool,
     *     sort_order: int,
     *     stages: array<string, array{key: string, label: string, sort_order: int, active: bool}>
     * }
     */
    public function get(string $relationshipKey): array
    {
        $relationshipKey = trim($relationshipKey);
        $definitions = $this->all();

        if (! isset($definitions[$relationshipKey])) {
            throw new InvalidArgumentException(
                "Unknown Contact relationship [{$relationshipKey}].",
            );
        }

        return $definitions[$relationshipKey];
    }

    public function has(string $relationshipKey): bool
    {
        return isset($this->all()[trim($relationshipKey)]);
    }

    public function stageExists(string $relationshipKey, string $stageKey): bool
    {
        $definition = $this->get($relationshipKey);

        return isset($definition['stages'][trim($stageKey)]);
    }

    /**
     * @param mixed $stages
     * @return array<string, array{key: string, label: string, sort_order: int, active: bool}>
     */
    private function normalizeStages(string $relationshipKey, mixed $stages): array
    {
        if (! is_array($stages)) {
            throw new InvalidArgumentException(
                "Relationship stages for [{$relationshipKey}] must be an array.",
            );
        }

        $normalized = [];

        foreach ($stages as $stageKey => $stageDefinition) {
            if (! is_string($stageKey) || ! preg_match(self::KEY_PATTERN, $stageKey)) {
                throw new InvalidArgumentException(
                    "Relationship stage keys for [{$relationshipKey}] must use lowercase snake_case.",
                );
            }

            if (is_string($stageDefinition)) {
                $stageDefinition = ['label' => $stageDefinition];
            }

            if (! is_array($stageDefinition)) {
                throw new InvalidArgumentException(
                    "Relationship stage [{$relationshipKey}.{$stageKey}] must be a string label or an array.",
                );
            }

            $this->rejectUnknownFields(
                value: $stageDefinition,
                allowed: ['label', 'sort_order', 'active'],
                path: "relationships.types.{$relationshipKey}.stages.{$stageKey}",
            );

            $normalized[$stageKey] = [
                'key' => $stageKey,
                'label' => $this->requiredLabel(
                    $stageDefinition['label'] ?? null,
                    "relationships.types.{$relationshipKey}.stages.{$stageKey}.label",
                ),
                'sort_order' => $this->integerValue(
                    $stageDefinition['sort_order'] ?? 0,
                    "relationships.types.{$relationshipKey}.stages.{$stageKey}.sort_order",
                ),
                'active' => $this->booleanValue(
                    $stageDefinition['active'] ?? true,
                    "relationships.types.{$relationshipKey}.stages.{$stageKey}.active",
                ),
            ];
        }

        uasort(
            $normalized,
            static fn (array $left, array $right): int => [
                $left['sort_order'],
                $left['key'],
            ] <=> [
                $right['sort_order'],
                $right['key'],
            ],
        );

        return $normalized;
    }

    private function requiredLabel(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Relationship configuration [{$path}] must be a non-empty string.",
            );
        }

        return trim($value);
    }

    private function booleanValue(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                "Relationship configuration [{$path}] must be boolean.",
            );
        }

        return $value;
    }

    private function integerValue(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException(
                "Relationship configuration [{$path}] must be an integer.",
            );
        }

        return $value;
    }
    /**
     * @param array<string, mixed> $value
     * @param array<int, string> $allowed
     */
    private function rejectUnknownFields(array $value, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));

        if ($unknown === []) {
            return;
        }

        sort($unknown);

        throw new InvalidArgumentException(sprintf(
            'Relationship configuration [%s] contains unknown field(s): %s.',
            $path,
            implode(', ', $unknown),
        ));
    }

}