<?php

namespace App\Modules\Core\Access\Services;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ContactVisibility
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    /** @param Builder<Contact> $query */
    public function apply(Builder $query, User $user): Builder
    {
        if (! $this->access->isActive($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->access->allows($user, 'contacts.view_all')) {
            return $query;
        }

        $teamIds = [];
        $teamUserIds = [];

        if ($this->access->allows($user, 'contacts.view_team')) {
            $teamIds = DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('team_user.user_id', $user->getKey())
                ->where('teams.is_active', true)
                ->pluck('team_user.team_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($teamIds !== []) {
                $teamUserIds = DB::table('team_user')
                    ->whereIn('team_id', $teamIds)
                    ->pluck('user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        $includeUnassigned = $this->access->allows($user, 'contacts.view_unassigned');

        return $query->where(function (Builder $visible) use (
            $user,
            $teamIds,
            $teamUserIds,
            $includeUnassigned,
        ): void {
            $visible->where('contacts.assigned_user_id', $user->getKey());

            if ($teamIds !== []) {
                $visible->orWhereIn('contacts.assigned_team_id', $teamIds);
            }

            if ($teamUserIds !== []) {
                $visible->orWhereIn('contacts.assigned_user_id', $teamUserIds);
            }

            if ($includeUnassigned) {
                $visible->orWhere(function (Builder $unassigned): void {
                    $unassigned
                        ->whereNull('contacts.assigned_user_id')
                        ->whereNull('contacts.assigned_team_id');
                });
            }
        });
    }

    public function canView(User $user, Contact $contact): bool
    {
        return $this->apply(
            Contact::query()->whereKey($contact->getKey()),
            $user,
        )->exists();
    }
}