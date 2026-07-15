<?php

namespace App\Modules\InternalNotifications\Services\Tasks;

use App\Models\User;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Contracts\TaskAssigneeOptionProviderContract;
use App\Modules\Tasks\Data\TaskAssigneeOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamMemberTaskAssigneeOptionProvider implements TaskAssigneeOptionProviderContract
{
    public function options(?Model $actor = null): Collection
    {
        $actorUserId = $actor instanceof User
            ? (int) $actor->getKey()
            : null;

        return TeamMember::query()
            ->active()
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->map(fn (TeamMember $teamMember): TaskAssigneeOption => new TaskAssigneeOption(
                assignee: $teamMember,
                label: $this->label($teamMember),
                description: $teamMember->email,
                isCurrent: $actorUserId !== null
                    && (int) $teamMember->user_id === $actorUserId,
            ))
            ->values();
    }

    private function label(TeamMember $teamMember): string
    {
        $name = trim((string) $teamMember->name);

        return $name !== ''
            ? $name
            : ($teamMember->email ?: 'Team Member #'.$teamMember->getKey());
    }
}
