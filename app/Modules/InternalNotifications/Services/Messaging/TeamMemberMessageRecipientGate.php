<?php

namespace App\Modules\InternalNotifications\Services\Messaging;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationGate;
use App\Modules\Messaging\Contracts\MessageRecipientGate;
use Illuminate\Database\Eloquent\Model;

class TeamMemberMessageRecipientGate implements MessageRecipientGate
{
    public function __construct(
        private readonly InternalNotificationGate $internalNotificationGate,
    ) {}

    public function supports(Model $recipient): bool
    {
        return $recipient instanceof TeamMember;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason(
            recipient: $recipient,
            channel: $channel,
            type: $type,
            context: $context,
        ) === null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        if (! $recipient instanceof TeamMember) {
            return null;
        }

        if (! $this->internalNotificationGate->allows(
            teamMember: $recipient,
            channel: $channel,
            notificationType: $type,
        )) {
            return 'Team member notification preference denied send.';
        }

        return null;
    }
}