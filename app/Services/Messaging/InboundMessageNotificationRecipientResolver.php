<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Models\InboundMessage;
use App\Models\TeamMember;
use App\Models\TeamMemberNotificationPreference;

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
            notificationType: TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            preferenceOwner: $teamMember,
        );
    }

    private function resolveFromContact(InboundMessage $inboundMessage): ?TeamMember
    {
        $sender = $inboundMessage->sender;

        if (! $sender instanceof Contact) {
            return null;
        }

        $assignedTo = trim((string) $sender->assigned_to);

        if ($assignedTo === '') {
            return null;
        }

        return $this->resolveAssignableTeamMember($assignedTo);
    }

    private function resolveAssignableTeamMember(string $assignedTo): ?TeamMember
    {
        $teamMember = TeamMember::query()
            ->with('notificationPreferences')
            ->active()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($assignedTo)])
            ->first();

        if ($teamMember) {
            return $teamMember;
        }

        return TeamMember::query()
            ->with('notificationPreferences')
            ->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($assignedTo)])
            ->first();
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