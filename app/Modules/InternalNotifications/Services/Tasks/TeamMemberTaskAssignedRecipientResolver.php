<?php

namespace App\Modules\InternalNotifications\Services\Tasks;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use App\Modules\Tasks\Data\TaskRecipient;
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
        if (! $this->supports($assignedTo)) {
            return collect();
        }

        return collect([
            new TaskRecipient(
                source: $assignedTo,
                name: $this->teamMemberName($assignedTo),
                email: $assignedTo->email,
                phone: $assignedTo->phone,
                preferenceOwner: $assignedTo,
            ),
        ]);
    }

    private function teamMemberName(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== ''
            ? $name
            : ($teamMember->email ?: 'Team Member #'.$teamMember->id);
    }
}
