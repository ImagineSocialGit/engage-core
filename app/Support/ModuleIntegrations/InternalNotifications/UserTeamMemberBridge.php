<?php

namespace App\Support\ModuleIntegrations\InternalNotifications;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\InternalNotifications\Models\TeamMember;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

final class UserTeamMemberBridge
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function resolve(User $user): ?TeamMember
    {
        if (! Schema::hasTable('team_members')) {
            return null;
        }

        $linked = TeamMember::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($linked->count() > 1) {
            return null;
        }

        $teamMember = $linked->first();

        if ($teamMember instanceof TeamMember) {
            return $teamMember;
        }

        $emailMatches = $this->emailMatches($user);

        if ($emailMatches->count() !== 1) {
            return null;
        }

        $teamMember = $emailMatches->first();

        if (! $teamMember instanceof TeamMember || $teamMember->user_id !== null) {
            return null;
        }

        $teamMember->forceFill([
            'user_id' => $user->getKey(),
        ])->save();

        return $teamMember->refresh();
    }

    public function resolveActive(User $user): ?TeamMember
    {
        if (! $this->access->isActive($user)) {
            return null;
        }

        $teamMember = $this->resolve($user);

        return $teamMember instanceof TeamMember && $teamMember->is_active
            ? $teamMember
            : null;
    }

    /** @return Collection<int, TeamMember> */
    private function emailMatches(User $user): Collection
    {
        $email = strtolower(trim((string) $user->email));

        if ($email === '') {
            return new Collection();
        }

        return TeamMember::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderBy('id')
            ->limit(2)
            ->get();
    }
}