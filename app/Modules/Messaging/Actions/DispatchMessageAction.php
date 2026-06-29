<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessagePlanningGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class DispatchMessageAction
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly MessagePlanningGate $planningGate,
        private readonly ScheduleMessageAction $scheduleMessageAction,
    ) {}

    /**
     * @param string|array<int, string> $dispatchKeys
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed> $criteria
     * @param array<int, array<string, mixed>>|array<string, mixed> $definitions
     * @return array<int, ScheduledMessage>
     */
    public function handle(
        Model $recipient,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
        string|array $dispatchKeys,
        array $payload = [],
        ?Model $context = null,
        Carbon|string|null $triggeredAt = null,
        Carbon|string|null $anchor = null,
        ?array $meta = null,
        array $criteria = [],
        array $definitions = [],
    ): array {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);
        $dispatchKeys = $this->normalizeDispatchKeys($dispatchKeys);
        $criteria = $this->normalizeCriteria($criteria);

        if ($dispatchKeys === []) {
            return [];
        }

        $triggeredAt = $triggeredAt ? Carbon::parse($triggeredAt) : now();
        $anchor = $anchor ? Carbon::parse($anchor) : null;

        $definitions = $definitions === []
            ? $this->messageDefinitionResolver->resolve(
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            )
            : $this->normalizeInlineDefinitions(
                definitions: $definitions,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            );

        $definitions = array_values(array_filter(
            $definitions,
            fn (array $definition): bool => $this->definitionMatchesDispatchKeys($definition, $dispatchKeys)
                && $this->definitionMatchesCriteria($definition, $criteria),
        ));

        $this->assertCriteriaMatchesSingleDefinition($definitions, $criteria);

        $scheduledMessages = [];

        foreach ($definitions as $definition) {
            $resolvedPayload = $this->payloadResolver->resolve(
                recipient: $recipient,
                channel: $definition['channel'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
                messageType: $definition['message_type'],
                definitionPayload: $definition['payload'] ?? [],
                payload: $payload,
            );

            if (! $resolvedPayload) {
                continue;
            }

            if (! $this->planningGate->allows(
                recipient: $recipient,
                channel: $definition['channel'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
                definition: $definition,
                payload: $resolvedPayload,
                context: $context,
            )) {
                continue;
            }

            $sendAt = $this->sendAt(
                definition: $definition,
                triggeredAt: $triggeredAt,
                anchor: $anchor,
            );

            if (($definition['timing'] ?? 'immediate') === 'scheduled' && $sendAt->lt(now())) {
                continue;
            }

            $messageMeta = array_replace_recursive(
                [
                    'queue' => $definition['queue'],
                    'definition_config_path' => $definition['config_path'],
                    'dispatch_keys' => $definition['dispatch_keys'],
                    'campaign_key' => $definition['campaign_key'] ?? null,
                    'campaign_step' => $definition['step'] ?? null,
                    'conditions' => $definition['conditions'] ?? [],
                    'schedule' => $definition['schedule'] ?? null,
                    'skip_when_join_clicked' => $definition['skip_when_join_clicked'] ?? false,
                    'notification_type' => $definition['notification_type'] ?? null,
                    'triggered_at' => $triggeredAt->toISOString(),
                    'anchor' => $anchor?->toISOString(),
                ],
                $meta ?? [],
            );

            $scheduledMessages[] = $this->scheduleMessageAction->handle(
                recipient: $recipient,
                channel: $definition['channel'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
                messageType: $definition['message_type'],
                payloadClass: $definition['payload_class'],
                payload: $resolvedPayload,
                sendAt: $sendAt,
                context: $context,
                dedupeKey: $this->dedupeKey($recipient, $definition, $context, $sendAt),
                meta: $messageMeta,
            );
        }

        return $scheduledMessages;
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $definitions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInlineDefinitions(
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
            $messageType = $this->normalizeOptionalSegment($definition['message_type'] ?? null)
                ?? $this->normalizeOptionalSegment($definition['type'] ?? null)
                ?? null;

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
                'timing' => $definition['timing'] ?? 'immediate',
                'schedule' => is_array($definition['schedule'] ?? null) ? $definition['schedule'] : null,
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

        foreach (['payload_class', 'queue', 'payload', 'timing', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] is missing [{$requiredKey}].");
            }
        }

        foreach (['channel', 'purpose', 'scope', 'message_type', 'payload_class', 'queue', 'timing'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey]) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [{$requiredStringKey}].");
            }
        }

        if (! in_array($definition['timing'], ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [timing].");
        }

        if (! is_array($definition['payload'])) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [payload].");
        }

        if (array_key_exists('conditions', $definition) && ! is_array($definition['conditions'])) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [conditions].");
        }

        if (! is_array($definition['dispatch_keys']) || $definition['dispatch_keys'] === []) {
            throw new InvalidArgumentException("Inline message definition [{$definitionLabel}] has invalid [dispatch_keys].");
        }

        if ($definition['timing'] === 'scheduled') {
            $this->validateSchedule($definition);
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function sendAt(array $definition, Carbon $triggeredAt, ?Carbon $anchor): Carbon
    {
        if (($definition['timing'] ?? null) === 'immediate') {
            return $triggeredAt->copy();
        }

        $schedule = $definition['schedule'] ?? null;

        if (! is_array($schedule)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] is missing [schedule].");
        }

        $type = $schedule['type'] ?? null;
        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.minutes].");
        }

        return match ($type) {
            'delay' => $triggeredAt->copy()->addMinutes($minutes),

            'anchored' => $anchor instanceof Carbon
                ? $anchor->copy()->addMinutes($minutes)
                : throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] requires an anchor."),

            default => throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.type]."),
        };
    }

    /**
     * @param array<string, mixed> $definition
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
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function normalizeCriteria(array $criteria): array
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
     */
    private function dedupeKey(
        Model $recipient,
        array $definition,
        ?Model $context,
        Carbon $sendAt,
    ): string {
        return implode(':', array_filter([
            'message',
            $recipient->getMorphClass(),
            $recipient->getKey(),
            $definition['channel'],
            $definition['purpose'],
            $definition['scope'],
            $definition['message_type'],

            $definition['campaign_key'] ?? null,
            $definition['step'] ?? null,

            $definition['timing'] ?? null,
            $definition['schedule']['type'] ?? null,
            $definition['schedule']['minutes'] ?? null,
            $sendAt->toISOString(),

            $context?->getMorphClass(),
            $context?->getKey(),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function normalizeEnumValue(MessageChannel|MessagePurpose|string $value): string
    {
        return $value instanceof MessageChannel || $value instanceof MessagePurpose
            ? $value->value
            : strtolower(trim($value));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function normalizeOptionalSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
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
}