<?php

namespace App\Modules\Tasks\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Tasks\Contracts\TaskAssigneeOptionProviderContract;
use App\Modules\Tasks\Data\TaskAssigneeOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CoreTaskAssigneeOptionProvider implements TaskAssigneeOptionProviderContract
{
    public function __construct(
        private readonly AssignmentDirectory $directory,
    ) {}

    public function options(?Model $actor = null): Collection
    {
        $users = $this->directory->activeUsers()->map(
            fn (User $user): TaskAssigneeOption => new TaskAssigneeOption(
                assignee: $user,
                label: $user->name ?: $user->email,
                description: 'Person',
                isCurrent: $actor instanceof User && $actor->is($user),
            ),
        );

        $teams = $this->directory->activeTeams()->map(
            fn (Team $team): TaskAssigneeOption => new TaskAssigneeOption(
                assignee: $team,
                label: $team->name.' queue',
                description: 'Team queue',
            ),
        );

        return $users->concat($teams)->values();
    }
}