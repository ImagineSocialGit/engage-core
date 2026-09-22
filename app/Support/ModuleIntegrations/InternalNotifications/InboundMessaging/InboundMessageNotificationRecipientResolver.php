<?php

namespace App\Support\ModuleIntegrations\InternalNotifications\InboundMessaging;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use Illuminate\Support\Collection;

class InboundMessageNotificationRecipientResolver
{
    public function resolve(InboundMessage $inboundMessage): ?InternalNotificationRecipient
    {
        return $this->resolveAll($inboundMessage)->first();
    }

    /**
     * @return Collection<int, InternalNotificationRecipient>
     */
    public function resolveAll(InboundMessage $inboundMessage): Collection
    {
        return TeamMember::query()
            ->with('notificationPreferences')
            ->active()
            ->whereNotNull('email')
            ->whereHas(
                'notificationPreferences',
                fn ($query) => $query
                    ->where(
                        'channel',
                        TeamMemberNotificationPreference::CHANNEL_EMAIL,
                    )
                    ->where(
                        'purpose',
                        TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
                    )
                    ->whereNull('scope')
                    ->where('is_enabled', true),
            )
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $teamMember): InternalNotificationRecipient =>
                new InternalNotificationRecipient(
                    source: $teamMember,
                    name: $this->teamMemberName($teamMember),
                    email: $teamMember->email,
                    phone: $teamMember->phone,
                    notificationType: TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
                    preferenceOwner: $teamMember,
                )
            );
    }

    private function teamMemberName(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== ''
            ? $name
            : ($teamMember->email ?: 'Team Member #'.$teamMember->getKey());
    }
}