<?php

namespace App\Services\CRM\Tasks\AssignedRecipients;

use App\Contracts\CRM\Tasks\TaskAssignedRecipientResolver;
use App\Models\TeamMember;
use App\Models\TeamMemberNotificationPreference;
use App\Services\Messaging\InternalNotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamMemberTaskAssignedRecipientResolver implements TaskAssignedRecipientResolver
{
    public function supports(Model $assignedTo): bool
    {
        return $assignedTo instanceof TeamMember;
    }

    public function resolve(Model $assignedTo): Collection
    {
        if (! $assignedTo instanceof TeamMember) {
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
}