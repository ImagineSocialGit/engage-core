<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\ResolvedMessageDispatch;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageDedupeKeyBuilder;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessageDispatchDefinitionMatcher;
use App\Modules\Messaging\Services\MessageDispatchDefinitionNormalizer;
use App\Modules\Messaging\Services\MessagePlanningGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use App\Modules\Messaging\Services\ResolvedMessageDispatchBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DispatchMessageAction
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageDispatchDefinitionNormalizer $definitionNormalizer,
        private readonly MessageDispatchDefinitionMatcher $definitionMatcher,
        private readonly ResolvedMessageDispatchBuilder $resolvedDispatchBuilder,
        private readonly MessageDedupeKeyBuilder $dedupeKeyBuilder,
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
     * @param array<string, mixed> $behavior
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
        Carbon|string|null $sendAt = null,
        ?Model $behaviorOwner = null,
        array $behavior = [],
        ?string $occurrenceKey = null,
    ): array {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);

        $dispatchKeys = $this->definitionMatcher->normalizeDispatchKeys($dispatchKeys);
        $criteria = $this->definitionMatcher->normalizeCriteria($criteria);

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
            : $this->definitionNormalizer->normalizeInlineDefinitions(
                definitions: $definitions,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            );

        $definitions = $this->definitionMatcher->matchingDefinitions(
            definitions: $definitions,
            dispatchKeys: $dispatchKeys,
            criteria: $criteria,
        );

        $scheduledMessages = [];

        foreach ($definitions as $definition) {
            $definitionBehaviorOwner = $definition['behavior_owner'] ?? null;
            $definitionBehavior = is_array($definition['resolved_behavior'] ?? null)
                ? $definition['resolved_behavior']
                : [];
            unset($definition['behavior_owner'], $definition['resolved_behavior']);

            $resolvedDispatch = $this->resolvedDispatchBuilder->build(
                template: $definition,
                triggeredAt: $triggeredAt,
                anchor: $anchor,
                sendAt: $sendAt,
                behavior: array_replace_recursive($definitionBehavior, $behavior),
                behaviorOwner: $definitionBehaviorOwner instanceof Model
                    ? $definitionBehaviorOwner
                    : $behaviorOwner,
                occurrenceKey: $occurrenceKey,
                meta: $meta ?? [],
            );

            $scheduledMessage = $this->handleResolved(
                recipient: $recipient,
                dispatch: $resolvedDispatch,
                payload: $payload,
                context: $context,
            );

            if ($scheduledMessage instanceof ScheduledMessage) {
                $scheduledMessages[] = $scheduledMessage;
            }
        }

        return $scheduledMessages;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleResolved(
        Model $recipient,
        ResolvedMessageDispatch $dispatch,
        array $payload = [],
        ?Model $context = null,
    ): ?ScheduledMessage {
        $definition = $dispatch->definition;

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
            return null;
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
            return null;
        }

        $dedupeKey = $this->dedupeKeyBuilder->build(
            recipient: $recipient,
            definition: $definition,
            context: $context,
            sendAt: $dispatch->sendAt,
            behaviorOwner: $dispatch->behaviorOwner,
            occurrenceKey: $dispatch->occurrenceKey,
        );

        if (
            ($definition['timing'] ?? null) === 'scheduled'
            && $dispatch->sendAt->lt(now())
            && ! $this->mayMergeIntoPendingConsolidatedDelivery(
                dedupeKey: $dedupeKey,
                dispatch: $dispatch,
            )
        ) {
            return null;
        }

        $messageMeta = array_replace_recursive(
            is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
            [
                'campaign_key' => $definition['campaign_key'] ?? null,
                'campaign_step' => $definition['step'] ?? null,
                'conditions' => is_array($definition['conditions'] ?? null)
                    ? $definition['conditions']
                    : [],
                'skip_when_join_clicked' => $definition['skip_when_join_clicked'] ?? false,
                'notification_type' => $definition['notification_type'] ?? null,
            ],
            $dispatch->meta,
        );

        return $this->scheduleMessageAction->handle(
            recipient: $recipient,
            channel: $definition['channel'],
            purpose: $definition['purpose'],
            scope: $definition['scope'],
            messageType: $definition['message_type'],
            payloadClass: $definition['payload_class'],
            payload: $resolvedPayload,
            sendAt: $dispatch->sendAt,
            context: $context,
            behaviorOwner: $dispatch->behaviorOwner,
            dedupeKey: $dedupeKey,
            meta: $messageMeta,
            queue: is_string($definition['queue'] ?? null)
                ? $definition['queue']
                : null,
            dispatchKeys: is_array($definition['dispatch_keys'] ?? null)
                ? $definition['dispatch_keys']
                : [],
            definitionConfigPath: is_string($definition['config_path'] ?? null)
                ? $definition['config_path']
                : null,
        );
    }

    private function mayMergeIntoPendingConsolidatedDelivery(
        string $dedupeKey,
        ResolvedMessageDispatch $dispatch,
    ): bool {
        if (! is_array(data_get($dispatch->meta, 'delivery_consolidation'))) {
            return false;
        }

        return ScheduledMessage::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->exists();
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
}