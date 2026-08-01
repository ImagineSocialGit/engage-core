<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\Delivery\MessageDeliveryComponent;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageDeliveryConsolidator;

class DispatchMessageIntentsAction
{
    public function __construct(
        private readonly MessageDeliveryConsolidator $consolidator,
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly AttachScheduledMessageComponentsAction $attachComponents,
    ) {}

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @return array<int, ScheduledMessage>
     */
    public function handle(
        array $intents,
        ?string $policyKey = null,
    ): array {
        $intents = array_values(array_filter(
            $intents,
            fn (mixed $intent): bool => $intent instanceof MessageDeliveryIntent,
        ));

        if ($intents === []) {
            return [];
        }

        if (is_string($policyKey) && trim($policyKey) !== '') {
            $intents = $this->consolidator->consolidate($intents, $policyKey);
        }

        $queue = $intents;
        $scheduledMessages = [];
        $seenFallbacks = [];

        while ($queue !== []) {
            /** @var MessageDeliveryIntent $intent */
            $intent = array_shift($queue);
            $messages = $this->dispatchIntent($intent);
            $coveredIntentKeys = [];

            foreach ($messages as $message) {
                $scheduledMessages[(int) $message->getKey()] = $message;
                $coveredIntentKeys = array_values(array_unique([
                    ...$coveredIntentKeys,
                    ...$this->attachComponents->handle(
                        scheduledMessage: $message,
                        components: $intent->components,
                    ),
                ]));
            }

            foreach ($intent->components as $component) {
                if (! $component instanceof MessageDeliveryComponent
                    || in_array($component->intentKey, $coveredIntentKeys, true)
                    || isset($seenFallbacks[spl_object_id($component->standaloneIntent)])
                ) {
                    continue;
                }

                $seenFallbacks[spl_object_id($component->standaloneIntent)] = true;
                $queue[] = $component->standaloneIntent;
            }
        }

        return array_values($scheduledMessages);
    }

    /**
     * @return array<int, ScheduledMessage>
     */
    private function dispatchIntent(
        MessageDeliveryIntent $intent,
    ): array {
        $definition = $intent->definition;
        $dispatchKeys = $definition['dispatch_keys']
            ?? $definition['dispatch_key']
            ?? [];

        return $this->dispatchMessage->handle(
            recipient: $intent->recipient,
            channel: (string) ($definition['channel'] ?? ''),
            purpose: (string) ($definition['purpose'] ?? ''),
            scope: (string) ($definition['scope'] ?? ''),
            dispatchKeys: $dispatchKeys,
            payload: $intent->payload,
            context: $intent->context,
            triggeredAt: $intent->triggeredAt,
            anchor: $intent->anchor,
            meta: $intent->meta,
            definitions: [$definition],
            sendAt: $intent->sendAt,
            behaviorOwner: $intent->behaviorOwner,
            behavior: $intent->behavior,
            occurrenceKey: $intent->occurrenceKey,
        );
    }
}