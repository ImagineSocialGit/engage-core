<?php

namespace App\Modules\InternalNotifications\Actions;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveInternalNotificationRecipientAction
{
    public function handle(
        string $name,
        string $email,
        bool $isActive,
        bool $receiveInboundReplies,
        bool $receiveScheduledReports,
        ?TeamMember $teamMember = null,
    ): TeamMember {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        if ($name === '' || $email === '') {
            throw ValidationException::withMessages([
                'email' => 'A recipient name and email address are required.',
            ]);
        }

        $duplicate = TeamMember::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when(
                $teamMember instanceof TeamMember,
                fn ($query) => $query->where('id', '!=', $teamMember->getKey()),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => 'That email address is already a notification recipient.',
            ]);
        }

        return DB::transaction(function () use (
            $name,
            $email,
            $isActive,
            $receiveInboundReplies,
            $receiveScheduledReports,
            $teamMember,
        ): TeamMember {
            $teamMember ??= new TeamMember();

            $meta = is_array($teamMember->meta)
                ? $teamMember->meta
                : [];

            $meta['notification_settings'] = [
                'managed' => true,
                'updated_at' => now()->toIso8601String(),
            ];

            $teamMember->forceFill([
                'name' => $name,
                'email' => $email,
                'is_active' => $isActive,
                'meta' => $meta,
            ])->save();

            $this->saveEmailPreference(
                $teamMember,
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
                $receiveInboundReplies,
            );

            $this->saveEmailPreference(
                $teamMember,
                TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
                $receiveScheduledReports,
            );

            return $teamMember->refresh()->load('notificationPreferences');
        }, 3);
    }

    private function saveEmailPreference(
        TeamMember $teamMember,
        string $type,
        bool $enabled,
    ): void {
        TeamMemberNotificationPreference::query()->updateOrCreate(
            [
                'team_member_id' => $teamMember->getKey(),
                'channel' => TeamMemberNotificationPreference::CHANNEL_EMAIL,
                'purpose' => $type,
                'scope' => null,
            ],
            [
                'is_enabled' => $enabled,
                'meta' => [
                    'managed_from' => 'settings.team_notifications',
                ],
            ],
        );
    }
}