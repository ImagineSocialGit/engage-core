<?php

namespace App\Support\ModuleIntegrations\InternalNotifications\Tasks;

use App\Models\User;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use App\Modules\Tasks\Data\TaskRecipient;
use App\Support\ModuleIntegrations\InternalNotifications\UserTeamMemberBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamMemberTaskAssignedRecipientResolver implements TaskAssignedRecipientResolver
{
    public function __construct(
        private readonly UserTeamMemberBridge $teamMembers,
    ) {}

    public function supports(Model $assignedTo): bool
    {
        return $assignedTo instanceof TeamMember || $assignedTo instanceof User;
    }

    public function resolve(Model $assignedTo): Collection
    {
        $teamMember = match (true) {
            $assignedTo instanceof User => $this->teamMembers->resolveActive($assignedTo),
            $assignedTo instanceof TeamMember && $assignedTo->is_active => $assignedTo,
            default => null,
        };

        if (! $teamMember instanceof TeamMember) {
            return collect();
        }

        return collect([
            new TaskRecipient(
                source: $teamMember,
                name: $this->teamMemberName($teamMember),
                email: $teamMember->email,
                phone: $teamMember->phone,
                preferenceOwner: $teamMember,
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