<?php

namespace App\Modules\InternalNotifications\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;

class InternalNotificationGate
{
    public function allows(
        TeamMember $teamMember,
        MessageChannel|string $channel,
        ?string $notificationType = null,
    ): bool {
        $channel = $this->normalizeChannel($channel);

        return match ($channel) {
            MessageChannel::Email->value => $this->allowsEmail($teamMember, $notificationType),
            MessageChannel::Sms->value => $this->allowsSms($teamMember, $notificationType),
            default => false,
        };
    }

    private function allowsEmail(TeamMember $teamMember, ?string $notificationType): bool
    {
        if (! $teamMember->is_active || ! $teamMember->email) {
            return false;
        }

        return $this->preferenceEnabled(
            teamMember: $teamMember,
            channel: MessageChannel::Email->value,
            notificationType: $notificationType,
            default: true,
        );
    }

    private function allowsSms(TeamMember $teamMember, ?string $notificationType): bool
    {
        if (! $teamMember->is_active || ! $teamMember->phone) {
            return false;
        }

        return $this->preferenceEnabled(
            teamMember: $teamMember,
            channel: MessageChannel::Sms->value,
            notificationType: $notificationType,
            default: false,
        );
    }

    private function preferenceEnabled(
        TeamMember $teamMember,
        string $channel,
        ?string $notificationType,
        bool $default,
    ): bool {
        if ($notificationType === null || trim($notificationType) === '') {
            return $default;
        }

        $teamMember->loadMissing('notificationPreferences');

        $preference = $teamMember->notificationPreferences
            ->first(fn (TeamMemberNotificationPreference $preference): bool => $preference->matches(
                channel: $channel,
                notificationType: $notificationType,
            ));

        return $preference?->is_enabled ?? $default;
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }
}