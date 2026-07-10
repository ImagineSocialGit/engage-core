<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

class MessageDispatchDefinitionNormalizer
{
    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $definitions
     * @return array<int, array<string, mixed>>
     */
    public function normalizeInlineDefinitions(
        array $definitions,
        string $channel,
        string $purpose,
        string $scope,
    ): array {
        if (! array_is_list($definitions)) {
            $definitions = [$definitions];
        }

        $normalized = [];

        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $definitionChannel = $this->normalizeOptionalSegment($definition['channel'] ?? null) ?? $channel;
            $definitionPurpose = $this->normalizeOptionalSegment($definition['purpose'] ?? null) ?? $purpose;
            $definitionScope = $this->normalizeOptionalSegment($definition['scope'] ?? null) ?? $scope;
            $messageType = $this->normalizeOptionalSegment($definition['message_type'] ?? null);

            if ($messageType === null) {
                throw new InvalidArgumentException('Inline message definition ['.$index.'] is missing [message_type].');
            }

            $dispatchKeys = $this->normalizeDefinitionDispatchKeys($definition);

            if ($dispatchKeys === []) {
                throw new InvalidArgumentException('Inline message definition ['.$index.'] has invalid [dispatch_keys].');
            }

            $normalizedDefinition = array_replace_recursive($definition, [
                'channel' => $definitionChannel,
                'purpose' => $definitionPurpose,
                'scope' => $definitionScope,
                'message_type' => $messageType,
                'config_path' => is_string($definition['config_path'] ?? null) && trim($definition['config_path']) !== ''
                    ? trim($definition['config_path'])
                    : null,
                'dispatch_keys' => $dispatchKeys,
            ]);

            $normalized[] = $this->validateInlineDefinition($normalizedDefinition);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function validateInlineDefinition(array $definition): array
    {
        $definitionLabel = is_string($definition['config_path'] ?? null) && trim($definition['config_path']) !== ''
            ? $definition['config_path']
            : 'inline message definition';

        foreach (['payload_class', 'queue', 'payload', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] is missing [{$requiredKey}].");
            }
        }

        foreach (['channel', 'purpose', 'scope', 'message_type', 'payload_class', 'queue'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey]) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [{$requiredStringKey}].");
            }
        }


        if (! is_array($definition['payload'])) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [payload].");
        }


        if (! is_array($definition['dispatch_keys']) || $definition['dispatch_keys'] === []) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [dispatch_keys].");
        }


        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function validateSchedule(array $definition): void
    {
        if (! is_array($definition['schedule'] ?? null)) {
            throw new InvalidArgumentException('Scheduled message definition is missing [schedule].');
        }

        $type = $definition['schedule']['type'] ?? null;
        $minutes = $definition['schedule']['minutes'] ?? null;

        if (! in_array($type, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.type].');
        }

        if (! is_int($minutes)) {
            throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.minutes].');
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, string>
     */
    private function normalizeDefinitionDispatchKeys(array $definition): array
    {
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && array_key_exists('dispatch_key', $definition)) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return $this->normalizeDispatchKeys($dispatchKeys);
    }

    /**
     * @param string|array<int, string> $dispatchKeys
     * @return array<int, string>
     */
    private function normalizeDispatchKeys(string|array $dispatchKeys): array
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

    private function normalizeOptionalSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
