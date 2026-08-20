<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use InvalidArgumentException;

class MessageTemplateCompositionConfigRegistry
{
    public function __construct(
        private readonly MessageTemplateCompositionSchema $schema,
    ) {}

    /**
     * @return array<int, array{
     *     config_key: string,
     *     scope_type: string,
     *     channel: string,
     *     client_key: ?string,
     *     context_key: ?string,
     *     family_key: ?string,
     *     message_template_id: null,
     *     payload: array<string, mixed>,
     *     source_version: ?string
     * }>
     */
    public function definitions(?string $clientKey = null): array
    {
        $configured = config('messaging.composition.layers', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'Messaging composition layers config must be an array.',
            );
        }

        $clientKey = $this->nullableString($clientKey ?? config('client.key'));
        $definitions = [];
        $seen = [];

        foreach ($configured as $configKey => $rawDefinition) {
            if (! is_array($rawDefinition)) {
                throw new InvalidArgumentException(
                    'Each Messaging composition layer must be an array.',
                );
            }

            $unsupported = array_diff(array_keys($rawDefinition), [
                'scope_type',
                'channel',
                'context_key',
                'family_key',
                'source_version',
                'payload',
            ]);

            if ($unsupported !== []) {
                throw new InvalidArgumentException(
                    'Messaging composition layer ['.(string) $configKey.'] contains unsupported key ['.(string) reset($unsupported).'].',
                );
            }

            $scopeType = $this->requiredString(
                $rawDefinition['scope_type'] ?? null,
                "Messaging composition layer [{$configKey}] scope_type",
            );

            if ($scopeType === MessageTemplateCompositionLayer::SCOPE_MESSAGE) {
                throw new InvalidArgumentException(
                    "Messaging composition layer [{$configKey}] may not use message scope in config; message overrides are DB-owned authoring state.",
                );
            }

            $channel = $this->requiredString(
                $rawDefinition['channel'] ?? null,
                "Messaging composition layer [{$configKey}] channel",
            );
            $payload = $rawDefinition['payload'] ?? null;

            if (! is_array($payload)) {
                throw new InvalidArgumentException(
                    "Messaging composition layer [{$configKey}] payload must be an array.",
                );
            }

            $normalized = $this->schema->normalize(
                scopeType: $scopeType,
                channel: $channel,
                payload: $payload,
                clientKey: $scopeType === MessageTemplateCompositionLayer::SCOPE_PLATFORM
                    ? null
                    : $clientKey,
                contextKey: $this->nullableString($rawDefinition['context_key'] ?? null),
                familyKey: $this->nullableString($rawDefinition['family_key'] ?? null),
                messageTemplateId: null,
            );

            if ($scopeType !== MessageTemplateCompositionLayer::SCOPE_PLATFORM
                && $normalized['client_key'] === null
            ) {
                throw new InvalidArgumentException(
                    "Messaging composition layer [{$configKey}] requires a selected client key.",
                );
            }

            $identity = $this->selectorIdentity($normalized);

            if (isset($seen[$identity])) {
                throw new InvalidArgumentException(
                    "Messaging composition layer [{$configKey}] duplicates another configured selector identity.",
                );
            }

            $seen[$identity] = true;
            $definitions[] = $normalized + [
                'config_key' => (string) $configKey,
                'source_version' => $this->nullableString($rawDefinition['source_version'] ?? null),
            ];
        }

        return $definitions;
    }

    /**
     * Resolve configured shared layers around one partial source definition.
     * Message-specific DB overrides are intentionally excluded from this pure
     * config validation path.
     *
     * @param array<string, mixed> $sourcePayload
     * @return array<string, mixed>
     */
    public function resolve(
        string $channel,
        array $sourcePayload,
        ?string $contextKey = null,
        ?string $familyKey = null,
        ?string $clientKey = null,
    ): array {
        $channel = $this->normalizeSegment($channel);
        $clientKey = $this->nullableNormalizedSegment($clientKey ?? config('client.key'));
        $contextKey = $this->nullableNormalizedSegment($contextKey);
        $familyKey = $this->nullableNormalizedSegment($familyKey);
        $definitions = $this->definitions($clientKey);
        $resolved = [];

        foreach ([
            MessageTemplateCompositionLayer::SCOPE_PLATFORM,
            MessageTemplateCompositionLayer::SCOPE_CLIENT,
            MessageTemplateCompositionLayer::SCOPE_FAMILY,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY,
        ] as $scopeType) {
            foreach ($definitions as $definition) {
                if ($definition['scope_type'] !== $scopeType
                    || $definition['channel'] !== $channel
                    || ! $this->matches(
                        definition: $definition,
                        clientKey: $clientKey,
                        contextKey: $contextKey,
                        familyKey: $familyKey,
                    )
                ) {
                    continue;
                }

                $resolved = $this->overlay($resolved, $definition['payload']);
                break;
            }
        }

        return $this->overlay($resolved, $sourcePayload);
    }

    public function clientKey(?string $clientKey = null): ?string
    {
        return $this->nullableNormalizedSegment($clientKey ?? config('client.key'));
    }

    /** @param array<string, mixed> $definition */
    private function selectorIdentity(array $definition): string
    {
        return hash('sha256', (string) json_encode([
            $definition['scope_type'],
            $definition['channel'],
            $definition['client_key'],
            $definition['context_key'],
            $definition['family_key'],
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function matches(
        array $definition,
        ?string $clientKey,
        ?string $contextKey,
        ?string $familyKey,
    ): bool {
        return match ($definition['scope_type']) {
            MessageTemplateCompositionLayer::SCOPE_PLATFORM => true,
            MessageTemplateCompositionLayer::SCOPE_CLIENT => $definition['client_key'] === $clientKey,
            MessageTemplateCompositionLayer::SCOPE_FAMILY => $definition['client_key'] === $clientKey
                && $definition['family_key'] === $familyKey,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT => $definition['client_key'] === $clientKey
                && $definition['context_key'] === $contextKey,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY => $definition['client_key'] === $clientKey
                && $definition['context_key'] === $contextKey
                && $definition['family_key'] === $familyKey,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function overlay(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ($value === null) {
                unset($base[$key]);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableNormalizedSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }
}