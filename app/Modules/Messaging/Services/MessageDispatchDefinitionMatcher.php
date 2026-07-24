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

        foreach ($criteria as $key => $value) {
            $key = $this->normalizeCriteriaKey($key);

            if (in_array($key, [
                'campaign_key',
                'message_type',
                'definition_key',
                'campaign_step_variant_key',
            ], true)) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("Dispatch criteria [{$key}] must be a non-empty string.");
                }

                $normalized[$key] = $this->normalizeSegment($value);

                continue;
            }

            if (in_array($key, [
                'source_config_path',
                'config_path',
                'campaign_step_variant_source_config_path',
            ], true)) {
                throw new InvalidArgumentException(
                    "Dispatch criteria [{$key}] is not supported; use stable semantic identity fields."
                );
            }

            if ($key === 'step') {
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException('Dispatch criteria [step] must be an integer greater than zero.');
                }

                $normalized['step'] = $value;

                continue;
            }

            if (is_array($value) || is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException("Dispatch criteria [{$key}] must be a scalar value or null.");
            }

            $normalized[$key] = is_string($value)
                ? trim($value)
                : $value;
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

        $definitionDispatchKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? $this->normalizeSegment($dispatchKey)
                : null,
            $definitionDispatchKeys,
        ))));

        return array_intersect($dispatchKeys, $definitionDispatchKeys) !== [];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $criteria
     */
    private function definitionMatchesCriteria(array $definition, array $criteria): bool
    {
        foreach ($criteria as $key => $expected) {
            $actual = $this->definitionCriteriaValue($definition, $key);

            if (in_array($key, [
                'source_config_path',
                'config_path',
                'campaign_step_variant_source_config_path',
            ], true)) {
                throw new InvalidArgumentException(
                    "Dispatch criteria [{$key}] is not supported; use stable semantic identity fields."
                );
            }

            if ($key === 'step') {
                if (! is_int($actual) || $actual !== $expected) {
                    return false;
                }

                continue;
            }

            if (in_array($key, [
                'campaign_key',
                'message_type',
                'definition_key',
                'campaign_step_variant_key',
            ], true)) {
                if (! is_string($actual) || $this->normalizeSegment($actual) !== $expected) {
                    return false;
                }

                continue;
            }

            if (is_string($actual) && is_string($expected)) {
                if ($this->normalizeSegment($actual) !== $this->normalizeSegment($expected)) {
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
     * @param array<string, mixed> $definition
     */
    private function definitionCriteriaValue(array $definition, string $key): mixed
    {
        return match ($key) {
            'campaign_key' => $definition['campaign_key']
                ?? data_get($definition, 'meta.campaign_template.campaign_key')
                ?? data_get($definition, 'meta.campaign.campaign_key'),
            'step' => $definition['step']
                ?? $definition['campaign_step']
                ?? data_get($definition, 'meta.campaign_template.step_number')
                ?? data_get($definition, 'meta.campaign.step'),
            'message_type' => $definition['message_type'] ?? null,
            'definition_key' => $definition['definition_key']
                ?? $definition['key']
                ?? data_get($definition, 'meta.message_template_assignment.definition_key')
                ?? data_get($definition, 'meta.seed.definition_key'),
            'campaign_step_variant_key' => $definition['campaign_step_variant_key']
                ?? $definition['variant']
                ?? data_get($definition, 'meta.campaign_template.campaign_step_variant_key')
                ?? data_get($definition, 'meta.message_template_assignment.campaign_step_variant_key'),
            default => data_get($definition, $key),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, mixed> $criteria
     */
    private function assertCriteriaMatchesSingleDefinition(array $definitions, array $criteria): void
    {
        if ($criteria === [] || count($definitions) <= 1) {
            return;
        }

        throw new InvalidArgumentException('Dispatch criteria matched multiple message definitions.');
    }

    private function normalizeCriteriaKey(mixed $key): string
    {
        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException('Dispatch criteria keys must be non-empty strings.');
        }

        return match ($this->normalizeSegment($key)) {
            'campaign_step' => 'step',
            'variant', 'variant_key' => 'campaign_step_variant_key',
            default => $this->normalizeSegment($key),
        };
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}