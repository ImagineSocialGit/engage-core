<x-layouts.crm
    title="Team"
    heading="Team"
    subheading="Manage CRM logins, simple role presets, Team membership, and Contact responsibility."
    module="core"
>
    <div class="space-y-6">
        <x-ui.card>
            <h2 class="text-lg font-semibold text-slate-900">How access works</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">
                Roles provide simple defaults. Owners and Admins can see the whole CRM. Managers can work across their Teams and unassigned Contacts. Team Members work with Contacts assigned directly to them. Viewers can read Team-visible Contacts without changing them.
            </p>
        </x-ui.card>

        @if($canManageTeam)
            <x-ui.card>
                <h2 class="text-lg font-semibold text-slate-900">Add team member</h2>
                <form method="POST" action="{{ route('crm.settings.team.members.store') }}" class="mt-4 grid gap-4 lg:grid-cols-2">
                    @csrf
                    <div>
                        <x-ui.form.label for="new_member_name">Name</x-ui.form.label>
                        <x-ui.form.input id="new_member_name" name="name" value="{{ old('name') }}" required />
                    </div>
                    <div>
                        <x-ui.form.label for="new_member_email">Email</x-ui.form.label>
                        <x-ui.form.input id="new_member_email" name="email" type="email" value="{{ old('email') }}" required />
                    </div>
                    <div>
                        <x-ui.form.label for="new_member_password">Temporary password</x-ui.form.label>
                        <x-ui.form.input id="new_member_password" name="password" type="password" required />
                    </div>
                    <div>
                        <x-ui.form.label for="new_member_role">Role</x-ui.form.label>
                        <select id="new_member_role" name="role_key" class="mt-1 w-full rounded-lg border-slate-300 text-sm" required>
                            @foreach($roleDefinitions as $roleKey => $role)
                                <option value="{{ $roleKey }}" @selected(old('role_key', config('access.default_role')) === $roleKey)>
                                    {{ $role['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset class="lg:col-span-2">
                        <legend class="text-sm font-medium text-slate-700">Teams</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @forelse($teams->where('is_active', true) as $team)
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="team_ids[]" value="{{ $team->id }}" @checked(in_array((string) $team->id, array_map('strval', old('team_ids', [])), true))>
                                    <span>{{ $team->name }}</span>
                                </label>
                            @empty
                                <span class="text-sm text-slate-500">Create a Team below when you need shared Contact visibility.</span>
                            @endforelse
                        </div>
                    </fieldset>
                    <div class="lg:col-span-2">
                        <x-ui.button type="submit">Add team member</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-slate-900">CRM users</h2>
            @foreach($members as $member)
                <x-ui.card>
                    @if($canManageTeam)
                        <form method="POST" action="{{ route('crm.settings.team.members.update', $member['user']) }}" class="space-y-4">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-4 lg:grid-cols-4">
                                <div>
                                    <x-ui.form.label for="member_name_{{ $member['user']->id }}">Name</x-ui.form.label>
                                    <x-ui.form.input id="member_name_{{ $member['user']->id }}" name="name" value="{{ $member['user']->name }}" required />
                                </div>
                                <div>
                                    <x-ui.form.label for="member_email_{{ $member['user']->id }}">Email</x-ui.form.label>
                                    <x-ui.form.input id="member_email_{{ $member['user']->id }}" name="email" type="email" value="{{ $member['user']->email }}" required />
                                </div>
                                <div>
                                    <x-ui.form.label for="member_role_{{ $member['user']->id }}">Role</x-ui.form.label>
                                    <select id="member_role_{{ $member['user']->id }}" name="role_key" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                        @foreach($roleDefinitions as $roleKey => $role)
                                            <option value="{{ $roleKey }}" @selected($member['role_key'] === $roleKey)>{{ $role['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-ui.form.label for="member_active_{{ $member['user']->id }}">Access</x-ui.form.label>
                                    <select id="member_active_{{ $member['user']->id }}" name="is_active" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                        <option value="1" @selected($member['is_active'])>Active</option>
                                        <option value="0" @selected(! $member['is_active'])>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <fieldset>
                                <legend class="text-sm font-medium text-slate-700">Teams</legend>
                                <div class="mt-2 flex flex-wrap gap-3">
                                    @foreach($teams->where('is_active', true) as $team)
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" name="team_ids[]" value="{{ $team->id }}" @checked(in_array((int) $team->id, $member['team_ids'], true))>
                                            <span>{{ $team->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <x-ui.button type="submit">Save access</x-ui.button>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $member['user']->name ?: $member['user']->email }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $member['user']->email }}</div>
                            </div>
                            <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                {{ $member['role_label'] }}
                            </span>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Teams</h2>
                    <p class="mt-1 text-sm text-slate-500">Use Teams when multiple people should share responsibility for the same Contacts.</p>
                </div>
            </div>

            @if($canManageTeam)
                <form method="POST" action="{{ route('crm.settings.team.teams.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf
                    <div class="min-w-0 flex-1">
                        <x-ui.form.label for="new_team_name">New Team name</x-ui.form.label>
                        <x-ui.form.input id="new_team_name" name="name" required />
                    </div>
                    <x-ui.button type="submit">Create Team</x-ui.button>
                </form>
            @endif

            <div class="mt-5 divide-y divide-slate-200">
                @forelse($teams as $team)
                    <div class="py-4">
                        @if($canManageTeam)
                            <form method="POST" action="{{ route('crm.settings.team.teams.update', $team) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px_auto] sm:items-end">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <x-ui.form.label for="team_name_{{ $team->id }}">Team name</x-ui.form.label>
                                    <x-ui.form.input id="team_name_{{ $team->id }}" name="name" value="{{ $team->name }}" required />
                                </div>
                                <div>
                                    <x-ui.form.label for="team_active_{{ $team->id }}">Status</x-ui.form.label>
                                    <select id="team_active_{{ $team->id }}" name="is_active" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                        <option value="1" @selected($team->is_active)>Active</option>
                                        <option value="0" @selected(! $team->is_active)>Inactive</option>
                                    </select>
                                </div>
                                <x-ui.button type="submit">Save Team</x-ui.button>
                            </form>
                        @else
                            <div class="flex items-center justify-between gap-4">
                                <div class="font-medium text-slate-900">{{ $team->name }}</div>
                                <div class="text-sm text-slate-500">{{ $team->users_count }} members</div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-sm text-slate-500">No Teams yet.</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</x-layouts.crm>