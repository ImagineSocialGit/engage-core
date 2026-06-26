<?php

namespace App\Modules\InternalNotifications\Services\InternalNotificationPreferences;

use App\Modules\InternalNotifications\Contracts\InternalNotificationPreferenceResolver;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationGate;
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