<?php

namespace App\Services\Messaging\InternalNotificationPreferences;

use App\Contracts\Messaging\InternalNotificationPreferenceResolver;
use App\Enums\MessageChannel;
use App\Models\TeamMember;
use App\Services\Messaging\InternalNotificationGate;
use Illuminate\Database\Eloquent\Model;

class TeamMemberInternalNotificationPreferenceResolver implements InternalNotificationPreferenceResolver
{
    public function __construct(
        private readonly InternalNotificationGate $gate,
    ) {}

    public function supports(Model $preferenceOwner): bool
    {
        return $preferenceOwner instanceof TeamMember;
    }

    public function allows(
        Model $preferenceOwner,
        MessageChannel|string $channel,
        ?string $notificationType = null,
    ): bool {
        if (! $preferenceOwner instanceof TeamMember) {
            return false;
        }

        return $this->gate->allows(
            teamMember: $preferenceOwner,
            channel: $channel,
            notificationType: $notificationType,
        );
    }
}