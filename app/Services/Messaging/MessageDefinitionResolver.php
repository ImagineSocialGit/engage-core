<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use InvalidArgumentException;

class MessageDefinitionResolver
{
    /**
     * Resolve all enabled message definitions for a channel/purpose/scope config route.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
    ): array {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizeSegment($purpose);
        $scope = $this->normalizeSegment($scope);

        if ($channel === '' || $purpose === '' || $scope === '') {
            return [];
        }

        $scopeConfigPath = "messaging.{$channel}.{$purpose}.{$scope}";
        $definitions = config($scopeConfigPath);

        if (! is_array($definitions)) {
            return [];
        }

        $resolved = [];

        foreach ($definitions as $messageType => $definition) {
            if (! is_string($messageType) || trim($messageType) === '' || ! is_array($definition)) {
                continue;
            }

            if (! ($definition['enabled'] ?? true)) {
                continue;
            }

            $resolved[] = $this->validateDefinition($this->hydrateDefinitionFromPath(
                definition: $definition,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                messageType: trim($messageType),
                configPath: "{$scopeConfigPath}.{$messageType}",
            ));
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function hydrateDefinitionFromPath(
        array $definition,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $configPath,
    ): array {

        $dispatchKeys = $this->normalizeDispatchKeys($definition);

        unset(
            $definition['channel'],
            $definition['purpose'],
            $definition['scope'],
            $definition['message_type'],
            $definition['config_path'],
            $definition['dispatch_key'],
            $definition['dispatch_keys'],
        );

        return array_replace_recursive($definition, [
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
            'message_type' => $messageType,
            'config_path' => $configPath,
            'dispatch_keys' => $dispatchKeys,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateDefinition(array $definition): array
    {
        foreach (['payload_class', 'queue', 'payload', 'timing', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] is missing [{$requiredKey}].");
            }
        }

        foreach (['payload_class', 'queue', 'timing'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey]) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [{$requiredStringKey}].");
            }
        }

        if (! in_array($definition['timing'], ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [timing].");
        }

        if (! is_array($definition['payload'])) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [payload].");
        }

        if (array_key_exists('conditions', $definition) && ! is_array($definition['conditions'])) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [conditions].");
        }

        if (! is_array($definition['dispatch_keys']) || $definition['dispatch_keys'] === []) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
        }

        foreach ($definition['dispatch_keys'] as $dispatchKey) {
            if (! is_string($dispatchKey) || trim($dispatchKey) === '') {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
            }
        }

        if ($definition['timing'] === 'scheduled') {
            $this->validateSchedule($definition);
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateSchedule(array $definition): void
    {
        if (! is_array($definition['schedule'] ?? null)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] is missing [schedule].");
        }

        $type = $definition['schedule']['type'] ?? null;
        $minutes = $definition['schedule']['minutes'] ?? null;

        if (! in_array($type, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.type].");
        }

        if (! is_int($minutes)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.minutes].");
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function normalizeDispatchKeys(array $definition): array
    {
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && array_key_exists('dispatch_key', $definition)) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? $this->normalizeSegment($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}