<?php

namespace App\Modules\Core\Access\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use Illuminate\Support\Collection;

final class AssignmentDirectory
{
    /** @var Collection<int, User>|null */
    private ?Collection $users = null;

    /** @var Collection<int, Team>|null */
    private ?Collection $teams = null;

    /** @return Collection<int, User> */
    public function activeUsers(): Collection
    {
        $inactiveIds = UserAccessProfile::query()
            ->where('is_active', false)
            ->pluck('user_id');

        return $this->users ??= User::query()
            ->when($inactiveIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $inactiveIds))
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }

    /** @return Collection<int, Team> */
    public function activeTeams(): Collection
    {
        return $this->teams ??= Team::query()
            ->active()
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    public function activeUser(int $id): ?User
    {
        return $this->activeUsers()->firstWhere('id', $id);
    }

    public function activeTeam(int $id): ?Team
    {
        return $this->activeTeams()->firstWhere('id', $id);
    }
}