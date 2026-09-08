<?php

namespace App\Modules\Core\Access\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Support\Users\CrmUserManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeamAccessManager
{
    public function __construct(
        private readonly CrmUserManager $crmUsers,
        private readonly UserAccessService $access,
    ) {}

    /** @param array<int, int|string> $teamIds */
    public function createMember(
        string $name,
        string $email,
        string $password,
        string $roleKey,
        array $teamIds,
        User $actor,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $roleKey, $teamIds, $actor): User {
            $user = $this->crmUsers->create(
                name: $name,
                email: $email,
                password: $password,
                roleKey: $roleKey,
            );

            $profile = UserAccessProfile::query()->firstOrNew([
                'user_id' => $user->getKey(),
            ]);

            $profile->forceFill([
                'role_key' => $roleKey,
                'is_active' => true,
                'capability_overrides' => $profile->capability_overrides,
                'meta' => $this->actorMeta($profile->meta, $actor),
            ])->save();

            $this->replaceMemberships($user, $teamIds);
            $this->access->forget($user);

            return $user->refresh();
        });
    }

    /** @param array<int, int|string> $teamIds */
    public function updateMember(
        User $user,
        string $name,
        string $email,
        string $roleKey,
        bool $isActive,
        array $teamIds,
        User $actor,
    ): User {
        if ($user->is($actor) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own CRM account.',
            ]);
        }

        if ($user->is($actor) && ! $this->access->roleAllows($roleKey, 'team.manage')) {
            throw ValidationException::withMessages([
                'role_key' => 'You cannot remove your own Team-management access.',
            ]);
        }

        return DB::transaction(function () use ($user, $name, $email, $roleKey, $isActive, $teamIds, $actor): User {
            $user->forceFill([
                'name' => trim($name),
                'email' => strtolower(trim($email)),
            ])->save();

            $profile = UserAccessProfile::query()->firstOrNew([
                'user_id' => $user->getKey(),
            ]);

            $profile->forceFill([
                'role_key' => $roleKey,
                'is_active' => $isActive,
                'capability_overrides' => $profile->capability_overrides,
                'meta' => $this->actorMeta($profile->meta, $actor),
            ])->save();

            $this->replaceMemberships($user, $teamIds);
            $this->access->forget($user);
            $this->ensureActiveTeamManagerRemains();

            return $user->refresh();
        });
    }

    public function createTeam(string $name, User $actor): Team
    {
        return Team::query()->create([
            'name' => trim($name),
            'is_active' => true,
            'meta' => $this->actorMeta(null, $actor),
        ]);
    }

    public function updateTeam(Team $team, string $name, bool $isActive, User $actor): Team
    {
        $team->forceFill([
            'name' => trim($name),
            'is_active' => $isActive,
            'meta' => $this->actorMeta($team->meta, $actor),
        ])->save();

        return $team->refresh();
    }

    /** @param array<int, int|string> $teamIds */
    private function replaceMemberships(User $user, array $teamIds): void
    {
        $teamIds = $this->normalizedTeamIds($teamIds);

        DB::table('team_user')
            ->where('user_id', $user->getKey())
            ->delete();

        if ($teamIds === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (int $teamId): array => [
            'team_id' => $teamId,
            'user_id' => (int) $user->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $teamIds);

        DB::table('team_user')->insert($rows);
    }

    /** @param array<int, int|string> $teamIds @return array<int, int> */
    private function normalizedTeamIds(array $teamIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (int|string $teamId): int => (int) $teamId,
            $teamIds,
        ), static fn (int $teamId): bool => $teamId > 0)));
    }

    /** @param array<string, mixed>|null $meta @return array<string, mixed> */
    private function actorMeta(?array $meta, User $actor): array
    {
        $meta = is_array($meta) ? $meta : [];
        $meta['access'] = [
            'last_actor_user_id' => (int) $actor->getKey(),
            'last_changed_at' => now()->toIso8601String(),
        ];

        return $meta;
    }

    private function ensureActiveTeamManagerRemains(): void
    {
        $profiledUserIds = UserAccessProfile::query()->pluck('user_id');

        if (User::query()->whereNotIn('id', $profiledUserIds)->exists()) {
            return;
        }

        $activeUserIds = UserAccessProfile::query()
            ->where('is_active', true)
            ->pluck('user_id');

        foreach (User::query()->whereIn('id', $activeUserIds)->get() as $user) {
            $this->access->forget($user);

            if ($this->access->allows($user, 'team.manage')) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'role_key' => 'At least one active Owner or Admin must retain Team-management access.',
        ]);
    }
}