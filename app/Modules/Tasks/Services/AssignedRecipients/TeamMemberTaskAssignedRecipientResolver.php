<?php

namespace App\Modules\Tasks\Services\AssignedRecipients;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamMemberTaskAssignedRecipientResolver implements TaskAssignedRecipientResolver
{
    public function supports(Model $assignedTo): bool
    {
        return $this->internalNotificationsEnabled()
            && $assignedTo instanceof TeamMember;
    }

    public function resolve(Model $assignedTo): Collection
    {
        if (! $this->supports($assignedTo)) {
            return collect();
        }

        return collect([
            new InternalNotificationRecipient(
                source: $assignedTo,
                name: $this->teamMemberName($assignedTo),
                email: $assignedTo->email,
                phone: $assignedTo->phone,
                notificationType: TeamMemberNotificationPreference::TYPE_TASK_ASSIGNED,
                preferenceOwner: $assignedTo,
            ),
        ]);
    }

    private function teamMemberName(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== '' ? $name : ($teamMember->email ?: 'Team Member #'.$teamMember->id);
    }

    private function internalNotificationsEnabled(): bool
    {
        return ! function_exists('module_enabled')
            || module_enabled('internal_notifications');
    }
}