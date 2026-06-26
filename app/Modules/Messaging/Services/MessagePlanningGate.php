<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationGate;
use App\Modules\Messaging\Services\ConditionChecker;
use Illuminate\Database\Eloquent\Model;

class MessagePlanningGate
{
    public function __construct(
        private readonly ConditionChecker $conditionChecker,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly InternalNotificationGate $internalNotificationGate,
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
                definitionConfigPath: $definition['config_path'] ?? null,
            );
        }

        if ($recipient instanceof TeamMember) {
            return $this->internalNotificationGate->allows(
                teamMember: $recipient,
                channel: $channel,
                notificationType: $this->notificationType($definition),
            );
        }

        return true;
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
    private function notificationType(array $definition): ?string
    {
        $notificationType = $definition['notification_type']
            ?? $definition['message_type']
            ?? null;

        return is_string($notificationType) && trim($notificationType) !== ''
            ? trim($notificationType)
            : null;
    }
}