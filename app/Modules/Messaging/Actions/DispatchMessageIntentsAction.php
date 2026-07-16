<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageDeliveryConsolidator;

class DispatchMessageIntentsAction
{
    public function __construct(
        private readonly MessageDeliveryConsolidator $consolidator,
        private readonly DispatchMessageAction $dispatchMessage,
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

        $scheduledMessages = [];

        foreach ($intents as $intent) {
            $definition = $intent->definition;
            $dispatchKeys = $definition['dispatch_keys'] ?? $definition['dispatch_key'] ?? [];

            $scheduledMessages = [
                ...$scheduledMessages,
                ...$this->dispatchMessage->handle(
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
                ),
            ];
        }

        return $scheduledMessages;
    }
}
