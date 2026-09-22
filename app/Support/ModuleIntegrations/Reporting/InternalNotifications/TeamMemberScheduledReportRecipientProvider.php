<?php

namespace App\Support\ModuleIntegrations\Reporting\InternalNotifications;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\Reporting\Contracts\ScheduledReportRecipientOptionProvider;
use App\Modules\Reporting\Data\ScheduledReportRecipientOption;

final class TeamMemberScheduledReportRecipientProvider implements ScheduledReportRecipientOptionProvider
{
    public function options(): iterable
    {
        return TeamMember::query()
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
                        TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
                    )
                    ->whereNull('scope')
                    ->where('is_enabled', true),
            )
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->map(fn (TeamMember $teamMember): ScheduledReportRecipientOption =>
                new ScheduledReportRecipientOption(
                    key: $teamMember->getMorphClass().':'.$teamMember->getKey(),
                    recipientType: $teamMember->getMorphClass(),
                    recipientId: (int) $teamMember->getKey(),
                    label: trim((string) $teamMember->name) !== ''
                        ? (string) $teamMember->name
                        : (string) $teamMember->email,
                    email: $teamMember->email,
                )
            );
    }
}