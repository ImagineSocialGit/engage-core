<x-ui.card class="space-y-5" data-module-panel="core">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Assignment</p>
        <h3 class="mt-1 text-lg font-semibold tracking-tight text-slate-950">{{ $contactPanel->title }}</h3>
        <p class="mt-1 text-sm leading-6 text-slate-600">
            Choose the person responsible for this Contact and, when useful, the Team that should share visibility.
        </p>
    </div>

    @if($canAssign)
        <form method="POST" action="{{ route('crm.contacts.assignment.update', $contact) }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="contact-assigned-user" class="text-sm font-semibold text-slate-900">Responsible person</label>
                    <select id="contact-assigned-user" name="assigned_user_id" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                        <option value="">Unassigned</option>
                        @foreach($assignableUsers as $user)
                            <option value="{{ $user['id'] }}" @selected((int) old('assigned_user_id', $assignedUser?->getKey()) === $user['id'])>{{ $user['label'] }}</option>
                        @endforeach
                    </select>
                    <x-ui.form.error name="assigned_user_id" />
                </div>

                <div>
                    <label for="contact-assigned-team" class="text-sm font-semibold text-slate-900">Team</label>
                    <select id="contact-assigned-team" name="assigned_team_id" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                        <option value="">No Team</option>
                        @foreach($assignableTeams as $team)
                            <option value="{{ $team['id'] }}" @selected((int) old('assigned_team_id', $assignedTeam?->getKey()) === $team['id'])>{{ $team['label'] }}</option>
                        @endforeach
                    </select>
                    <x-ui.form.error name="assigned_team_id" />
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5 text-slate-500">When both are selected, the responsible person must belong to the Team.</p>
                <x-ui.button type="submit" class="w-full sm:w-auto">Update ownership</x-ui.button>
            </div>
        </form>
    @else
        <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm ring-1 ring-slate-200">
            <span class="font-semibold text-slate-900">{{ $assignedUser?->name ?? $assignedUser?->email ?? 'Unassigned' }}</span>
            <span class="text-slate-500"> · {{ $assignedTeam?->name ?? 'No Team' }}</span>
        </div>
    @endif
</x-ui.card>