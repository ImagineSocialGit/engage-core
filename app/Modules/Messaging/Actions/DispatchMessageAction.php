<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageDedupeKeyBuilder;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessageDispatchDefinitionMatcher;
use App\Modules\Messaging\Services\MessageDispatchDefinitionNormalizer;
use App\Modules\Messaging\Services\MessagePlanningGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use App\Modules\Messaging\Services\MessageSendTimeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DispatchMessageAction
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageDispatchDefinitionNormalizer $definitionNormalizer,
        private readonly MessageDispatchDefinitionMatcher $definitionMatcher,
        private readonly MessageSendTimeResolver $sendTimeResolver,
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

            $sendAt = $this->sendTimeResolver->resolve(
                definition: $definition,
                triggeredAt: $triggeredAt,
                anchor: $anchor,
            );

            if (($definition['timing'] ?? 'immediate') === 'scheduled' && $sendAt->lt(now())) {
                continue;
            }

            $messageMeta = array_replace_recursive(
                is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
                [
                    'queue' => $definition['queue'],
                    'definition_config_path' => $definition['config_path'] ?? null,
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
                dedupeKey: $this->dedupeKeyBuilder->build(
                    recipient: $recipient,
                    definition: $definition,
                    context: $context,
                    sendAt: $sendAt,
                ),
                meta: $messageMeta,
            );
        }

        return $scheduledMessages;
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
