<?php

namespace App\Support\ModuleIntegrations\InternalNotifications\Tasks;

use App\Models\User;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use App\Modules\Tasks\Data\TaskRecipient;
use App\Modules\Core\Access\Models\Team;
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
        return $assignedTo instanceof TeamMember || $assignedTo instanceof User || $assignedTo instanceof Team;
    }

    public function resolve(Model $assignedTo): Collection
    {
        if ($assignedTo instanceof Team) {
            return $assignedTo->users()
                ->orderBy('users.id')
                ->get()
                ->map(fn (User $user): ?TeamMember => $this->teamMembers->resolveActive($user))
                ->filter()
                ->unique('id')
                ->map(fn (TeamMember $teamMember): TaskRecipient => $this->recipient($teamMember))
                ->values();
        }

        $teamMember = match (true) {
            $assignedTo instanceof User => $this->teamMembers->resolveActive($assignedTo),
            $assignedTo instanceof TeamMember && $assignedTo->is_active => $assignedTo,
            default => null,
        };

        if (! $teamMember instanceof TeamMember) {
            return collect();
        }

        return collect([$this->recipient($teamMember)]);
    }

    private function recipient(TeamMember $teamMember): TaskRecipient
    {
        return new TaskRecipient(
            source: $teamMember,
            name: $this->teamMemberName($teamMember),
            email: $teamMember->email,
            phone: $teamMember->phone,
            preferenceOwner: $teamMember,
        );
    }

    private function teamMemberName(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== ''
            ? $name
            : ($teamMember->email ?: 'Team Member #'.$teamMember->id);
    }
}