<?php

namespace App\Modules\Core\Access\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TeamRoundRobinAssigneeResolver
{
    public function next(Team $team, string $scope): User
    {
        $scope = trim($scope);

        if ($scope === '') {
            throw new InvalidArgumentException('Round-robin scope is required.');
        }

        return DB::transaction(function () use ($team, $scope): User {
            $locked = Team::query()->lockForUpdate()->findOrFail($team->getKey());

            if (! $locked->is_active) {
                throw new InvalidArgumentException('The selected Team is inactive.');
            }

            $inactiveIds = UserAccessProfile::query()
                ->where('is_active', false)
                ->pluck('user_id');

            $members = $locked->users()
                ->when($inactiveIds->isNotEmpty(), fn ($query) => $query->whereNotIn('users.id', $inactiveIds))
                ->orderBy('users.id')
                ->get();

            if ($members->isEmpty()) {
                throw new InvalidArgumentException('The selected Team has no active members.');
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $lastId = (int) data_get($meta, "round_robin.{$scope}.last_user_id", 0);
            $next = $members->first(fn (User $member): bool => (int) $member->getKey() > $lastId)
                ?? $members->first();

            $meta['round_robin'][$scope] = [
                'last_user_id' => (int) $next->getKey(),
                'advanced_at' => now()->toIso8601String(),
            ];

            $locked->forceFill(['meta' => $meta])->save();

            return $next;
        });
    }
}