<?php

namespace App\Modules\Core\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Access\Services\TeamAccessManager;
use App\Modules\Core\Access\Services\UserAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class TeamAccessController extends Controller
{
    public function index(Request $request, UserAccessService $access): View
    {
        $profiles = UserAccessProfile::query()
            ->get()
            ->keyBy('user_id');

        $memberships = DB::table('team_user')
            ->orderBy('team_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows): array => $rows
                ->pluck('team_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

        $members = User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->map(function (User $user) use ($profiles, $memberships, $access): array {
                $profile = $profiles->get($user->getKey());

                return [
                    'user' => $user,
                    'role_key' => $profile?->role_key ?? $access->roleKey($user),
                    'role_label' => $access->roleLabel($user),
                    'is_active' => $profile?->is_active ?? true,
                    'team_ids' => $memberships->get($user->getKey(), []),
                ];
            });

        return view('crm.settings.team', [
            'members' => $members,
            'teams' => Team::query()->withCount('users')->orderBy('name')->get(),
            'roleDefinitions' => $access->roleDefinitions(),
            'canManageTeam' => $request->user() instanceof User
                && $access->allows($request->user(), 'team.manage'),
        ]);
    }

    public function storeMember(Request $request, TeamAccessManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role_key' => ['required', 'string', Rule::in(array_keys(config('access.roles', [])))],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => [
                'integer',
                Rule::exists('teams', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $manager->createMember(
            name: $validated['name'],
            email: $validated['email'],
            password: $validated['password'],
            roleKey: $validated['role_key'],
            teamIds: $validated['team_ids'] ?? [],
            actor: $request->user(),
        );

        return back()->with('success', 'Team member added.');
    }

    public function updateMember(Request $request, User $user, TeamAccessManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'role_key' => ['required', 'string', Rule::in(array_keys(config('access.roles', [])))],
            'is_active' => ['required', 'boolean'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => [
                'integer',
                Rule::exists('teams', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $manager->updateMember(
            user: $user,
            name: $validated['name'],
            email: $validated['email'],
            roleKey: $validated['role_key'],
            isActive: (bool) $validated['is_active'],
            teamIds: $validated['team_ids'] ?? [],
            actor: $request->user(),
        );

        return back()->with('success', 'Team member access updated.');
    }

    public function storeTeam(Request $request, TeamAccessManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')],
        ]);

        $manager->createTeam($validated['name'], $request->user());

        return back()->with('success', 'Team created.');
    }

    public function updateTeam(Request $request, Team $team, TeamAccessManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->ignore($team->getKey()),
            ],
            'is_active' => ['required', 'boolean'],
        ]);

        $manager->updateTeam(
            team: $team,
            name: $validated['name'],
            isActive: (bool) $validated['is_active'],
            actor: $request->user(),
        );

        return back()->with('success', 'Team updated.');
    }
}