<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Data\ResolvedMessageDispatch;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Carbon;

class MessageDeliveryPrimarySelector
{
    public function __construct(
        private readonly ResolvedMessageDispatchBuilder $resolvedDispatchBuilder,
        private readonly MessageDedupeKeyBuilder $dedupeKeyBuilder,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly MessagePlanningGate $planningGate,
    ) {}

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @param array<string, mixed> $group
     * @param array<int, bool> $consumed
     */
    public function select(
        array $intents,
        array $group,
        array $consumed = [],
    ): ?MessageDeliveryIntent {
        $channel = $this->nullableSegment($group['channel'] ?? null);
        $primaryIntentKey = $this->nullableSegment(
            $group['primary_intent'] ?? null,
        );

        if ($channel === null || $primaryIntentKey === null) {
            return null;
        }

        $primaryCandidates = array_values(array_filter(
            $intents,
            fn (MessageDeliveryIntent $intent): bool =>
                ! isset($consumed[spl_object_id($intent)])
                && $intent->channel() === $channel
                && $this->normalizeSegment($intent->key) === $primaryIntentKey,
        ));

        $selected = $this->earliestEligible($primaryCandidates);

        if ($selected instanceof MessageDeliveryIntent) {
            return $selected;
        }

        $fallbackMessageTypes = array_values(array_unique(array_filter(
            array_map(
                fn (mixed $value): ?string => $this->nullableSegment($value),
                is_array($group['fallback_message_types'] ?? null)
                    ? $group['fallback_message_types']
                    : [],
            ),
        )));

        if ($fallbackMessageTypes === []) {
            return null;
        }

        $fallbackCandidates = array_values(array_filter(
            $intents,
            function (MessageDeliveryIntent $intent) use (
                $channel,
                $fallbackMessageTypes,
                $consumed,
            ): bool {
                if (isset($consumed[spl_object_id($intent)])) {
                    return false;
                }

                if ($intent->channel() !== $channel) {
                    return false;
                }

                $messageType = $this->nullableSegment(
                    $intent->definition['message_type'] ?? null,
                );

                return $messageType !== null
                    && in_array($messageType, $fallbackMessageTypes, true);
            },
        ));

        return $this->earliestEligible($fallbackCandidates);
    }

    /**
     * @param array<int, MessageDeliveryIntent> $candidates
     */
    private function earliestEligible(array $candidates): ?MessageDeliveryIntent
    {
        $eligible = [];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolvedCandidate($candidate);

            if ($resolved === null) {
                continue;
            }

            $eligible[] = $resolved;
        }

        usort(
            $eligible,
            static fn (array $left, array $right): int =>
                $left['send_at']->getTimestamp()
                    <=> $right['send_at']->getTimestamp(),
        );

        return $eligible[0]['intent'] ?? null;
    }

    /**
     * @return array{intent: MessageDeliveryIntent, send_at: Carbon}|null
     */
    private function resolvedCandidate(
        MessageDeliveryIntent $intent,
    ): ?array {
        $dispatch = $this->resolveDispatch($intent);
        $definition = $dispatch->definition;

        if ((bool) ($definition['skip_when_join_clicked'] ?? false)) {
            return null;
        }

        $resolvedPayload = $this->payloadResolver->resolve(
            recipient: $intent->recipient,
            channel: (string) ($definition['channel'] ?? ''),
            purpose: (string) ($definition['purpose'] ?? ''),
            scope: (string) ($definition['scope'] ?? ''),
            messageType: (string) ($definition['message_type'] ?? ''),
            definitionPayload: is_array($definition['payload'] ?? null)
                ? $definition['payload']
                : [],
            payload: $intent->payload,
        );

        if ($resolvedPayload === null) {
            return null;
        }

        if (! $this->planningGate->allows(
            recipient: $intent->recipient,
            channel: (string) ($definition['channel'] ?? ''),
            purpose: (string) ($definition['purpose'] ?? ''),
            scope: (string) ($definition['scope'] ?? ''),
            definition: $definition,
            payload: $resolvedPayload,
            context: $intent->context,
        )) {
            return null;
        }

        $dedupeKey = $this->dedupeKeyBuilder->build(
            recipient: $intent->recipient,
            definition: $definition,
            context: $intent->context,
            sendAt: $dispatch->sendAt,
            behaviorOwner: $dispatch->behaviorOwner,
            occurrenceKey: $dispatch->occurrenceKey,
        );

        $existing = ScheduledMessage::query()
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing instanceof ScheduledMessage) {
            if ($existing->status !== ScheduledMessage::STATUS_PENDING) {
                return null;
            }

            return [
                'intent' => $intent,
                'send_at' => $existing->send_at ?? $dispatch->sendAt,
            ];
        }

        if (
            ($definition['timing'] ?? null) === 'scheduled'
            && $dispatch->sendAt->lt(now())
        ) {
            return null;
        }

        return [
            'intent' => $intent,
            'send_at' => $dispatch->sendAt,
        ];
    }

    private function resolveDispatch(
        MessageDeliveryIntent $intent,
    ): ResolvedMessageDispatch {
        $definition = $intent->definition;
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if (
            $dispatchKeys === null
            && is_string($definition['dispatch_key'] ?? null)
        ) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        $definition['dispatch_keys'] = is_array($dispatchKeys)
            ? $dispatchKeys
            : [];

        unset($definition['dispatch_key']);

        return $this->resolvedDispatchBuilder->build(
            template: $definition,
            triggeredAt: $intent->triggeredAt,
            anchor: $intent->anchor,
            sendAt: $intent->sendAt,
            behavior: $intent->behavior,
            behaviorOwner: $intent->behaviorOwner,
            occurrenceKey: $intent->occurrenceKey,
            meta: $intent->meta,
        );
    }

    private function nullableSegment(mixed $value): ?string
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