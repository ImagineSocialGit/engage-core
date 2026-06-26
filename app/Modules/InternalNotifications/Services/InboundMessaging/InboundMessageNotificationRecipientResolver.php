<?php

namespace App\Modules\InternalNotifications\Services\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;

class InboundMessageNotificationRecipientResolver
{
    public function resolve(InboundMessage $inboundMessage): ?InternalNotificationRecipient
    {
        $teamMember = $this->resolveFromContact($inboundMessage)
            ?? $this->resolveDefaultTeamMember();

        if (! $teamMember) {
            return null;
        }

        return new InternalNotificationRecipient(
            source: $teamMember,
            name: $this->teamMemberName($teamMember),
            email: $teamMember->email,
            phone: $teamMember->phone,
            notificationType: 'inbound_replies',
            preferenceOwner: $teamMember,
        );
    }

    private function resolveFromContact(InboundMessage $inboundMessage): ?TeamMember
    {
        $sender = $inboundMessage->sender;

        if (! $sender instanceof Contact) {
            return null;
        }

        $sender->loadMissing('workflowProfile.assignedTo');

        $assignedTo = $sender->workflowProfile?->assignedTo;

        if (! $assignedTo instanceof TeamMember) {
            return null;
        }

        if (! $assignedTo->is_active) {
            return null;
        }

        return $assignedTo->loadMissing('notificationPreferences');
    }

    private function resolveDefaultTeamMember(): ?TeamMember
    {
        $email = trim((string) config(
            'messaging.internal_notifications.inbound_replies.default_team_member_email',
            ''
        ));

        if ($email === '') {
            return null;
        }

        return TeamMember::query()
            ->with('notificationPreferences')
            ->active()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    private function teamMemberName(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== '' ? $name : ($teamMember->email ?: 'Team Member #'.$teamMember->id);
    }
}