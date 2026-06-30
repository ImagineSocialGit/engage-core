<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

class MessageDispatchDefinitionMatcher
{
    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<int, string> $dispatchKeys
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function matchingDefinitions(
        array $definitions,
        array $dispatchKeys,
        array $criteria,
    ): array {
        $definitions = array_values(array_filter(
            $definitions,
            fn (array $definition): bool => $this->definitionMatchesDispatchKeys($definition, $dispatchKeys)
                && $this->definitionMatchesCriteria($definition, $criteria),
        ));

        $this->assertCriteriaMatchesSingleDefinition($definitions, $criteria);

        return $definitions;
    }

    /**
     * @param string|array<int, string> $dispatchKeys
     * @return array<int, string>
     */
    public function normalizeDispatchKeys(string|array $dispatchKeys): array
    {
        $dispatchKeys = is_string($dispatchKeys)
            ? [$dispatchKeys]
            : $dispatchKeys;

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? $this->normalizeSegment($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function normalizeCriteria(array $criteria): array
    {
        $normalized = [];

        if (array_key_exists('campaign_key', $criteria)) {
            if (! is_string($criteria['campaign_key']) || trim($criteria['campaign_key']) === '') {
                throw new InvalidArgumentException('Dispatch criteria [campaign_key] must be a non-empty string.');
            }

            $normalized['campaign_key'] = $this->normalizeSegment($criteria['campaign_key']);
        }

        if (array_key_exists('step', $criteria)) {
            if (! is_int($criteria['step']) || $criteria['step'] < 1) {
                throw new InvalidArgumentException('Dispatch criteria [step] must be an integer greater than zero.');
            }

            $normalized['step'] = $criteria['step'];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $dispatchKeys
     */
    private function definitionMatchesDispatchKeys(array $definition, array $dispatchKeys): bool
    {
        $definitionDispatchKeys = $definition['dispatch_keys'] ?? [];

        if (! is_array($definitionDispatchKeys)) {
            return false;
        }

        return array_intersect($dispatchKeys, $definitionDispatchKeys) !== [];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $criteria
     */
    private function definitionMatchesCriteria(array $definition, array $criteria): bool
    {
        foreach ($criteria as $key => $expected) {
            if (! array_key_exists($key, $definition)) {
                return false;
            }

            $actual = $definition[$key];

            if ($key === 'campaign_key') {
                if (! is_string($actual) || $this->normalizeSegment($actual) !== $expected) {
                    return false;
                }

                continue;
            }

            if ($key === 'step') {
                if (! is_int($actual) || $actual !== $expected) {
                    return false;
                }

                continue;
            }

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, mixed> $criteria
     */
    private function assertCriteriaMatchesSingleDefinition(array $definitions, array $criteria): void
    {
        if ($criteria === []) {
            return;
        }

        if (count($definitions) <= 1) {
            return;
        }

        throw new InvalidArgumentException('Dispatch criteria matched multiple message definitions.');
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}