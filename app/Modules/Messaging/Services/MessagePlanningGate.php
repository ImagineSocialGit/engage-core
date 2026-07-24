<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;

class MessagePlanningGate
{
    public function __construct(
        private readonly ConditionChecker $conditionChecker,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly MessageRecipientGateRegistry $recipientGateRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $payload
     */
    public function allows(
        Model $recipient,
        string $channel,
        string $purpose,
        string $scope,
        array $definition,
        array $payload,
        ?Model $context = null,
    ): bool {
        if (! $this->definitionIsEnabled($definition)) {
            return false;
        }

        if (! $this->hasDestination($payload)) {
            return false;
        }

        if (! $this->conditionChecker->passes(
            conditions: $definition['conditions'] ?? [],
            context: $this->payloadResolver->conditionContext($recipient, $context, $payload),
        )) {
            return false;
        }

        if ($recipient instanceof Contact) {
            return $this->messageEligibilityGate->allows(
                contact: $recipient,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                messageKey: $definition['message_type'] ?? null,
                context: $this->eligibilityContext($definition),
            );
        }

        return $this->recipientGateRegistry->allows(
            recipient: $recipient,
            channel: $channel,
            type: $this->recipientGateType($definition),
            context: [
                'purpose' => $purpose,
                'scope' => $scope,
                'definition' => $definition,
                'payload' => $payload,
                'context' => $context,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function definitionIsEnabled(array $definition): bool
    {
        return (bool) ($definition['enabled'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasDestination(array $payload): bool
    {
        return is_string($payload['to'] ?? null)
            && trim($payload['to']) !== '';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function recipientGateType(array $definition): ?string
    {
        $type = $definition['notification_type']
            ?? $definition['message_type']
            ?? null;

        return is_string($type) && trim($type) !== ''
            ? trim($type)
            : null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function eligibilityContext(array $definition): array
    {
        $consentPolicy = $definition['consent_policy']
            ?? $definition['meta']['consent_policy']
            ?? [];

        return [
            'consent_policy' => is_array($consentPolicy) ? $consentPolicy : [],
        ];
    }
}