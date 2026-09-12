<form method="POST" action="{{ route('crm.contacts.results.assignment') }}" class="space-y-3" x-data="{ mode: 'user' }">
    @csrf

    <input type="hidden" name="contact_result[search]" value="{{ $contactResultPayload['search'] ?? '' }}">
    @foreach(($contactResultPayload['criteria'] ?? []) as $criterion => $values)
        @foreach($values as $value)
            <input type="hidden" name="contact_result[criteria][{{ $criterion }}][]" value="{{ $value }}">
        @endforeach
    @endforeach

    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    </div>

    <select name="assignment_mode" x-model="mode" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="user">Assign to one person</option>
        <option value="team">Assign to a Team queue</option>
        <option value="team_round_robin">Round-robin across a Team</option>
        <option value="unassign">Remove assignment</option>
    </select>

    <select name="assigned_user_id" x-show="mode === 'user'" x-bind:disabled="mode !== 'user'" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">Choose a person</option>
        @foreach($contactResultAction->data['users'] as $user)
            <option value="{{ $user->getKey() }}">{{ $user->name ?: $user->email }}</option>
        @endforeach
    </select>

    <select name="assigned_team_id" x-show="mode === 'team' || mode === 'team_round_robin'" x-bind:disabled="mode !== 'team' && mode !== 'team_round_robin'" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">Choose a Team</option>
        @foreach($contactResultAction->data['teams'] as $team)
            <option value="{{ $team->getKey() }}">{{ $team->name }}</option>
        @endforeach
    </select>

    <label class="flex items-start gap-2 text-xs leading-5 text-slate-600" x-show="mode !== 'unassign'">
        <input type="hidden" name="only_unassigned" value="0">
        <input type="checkbox" name="only_unassigned" value="1" checked x-bind:disabled="mode === 'unassign'" class="mt-1 rounded border-slate-300">
        <span>Only assign currently unassigned Contacts</span>
    </label>

    <x-ui.button type="submit">Apply to {{ number_format($contactResultCount) }} Contact(s)</x-ui.button>
</form>