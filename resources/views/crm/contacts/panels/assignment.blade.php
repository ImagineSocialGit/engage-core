<div class="space-y-4">
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-slate-500">Person</dt>
            <dd class="mt-1 font-medium text-slate-900">
                {{ $assignedUser?->name ?: $assignedUser?->email ?: 'Unassigned' }}
            </dd>
        </div>
        <div>
            <dt class="text-slate-500">Team</dt>
            <dd class="mt-1 font-medium text-slate-900">
                {{ $assignedTeam?->name ?: 'Unassigned' }}
            </dd>
        </div>
    </dl>

    @if($canAssign)
        <form method="POST" action="{{ route('crm.contacts.assignment.update', $contact) }}" class="space-y-4 border-t border-slate-200 pt-4">
            @csrf
            @method('PATCH')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-ui.form.label for="assigned_user_id">Responsible person</x-ui.form.label>
                    <select id="assigned_user_id" name="assigned_user_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="">Unassigned</option>
                        @foreach($assignableUsers as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('assigned_user_id', $contact->assigned_user_id) === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('assigned_user_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-ui.form.label for="assigned_team_id">Team</x-ui.form.label>
                    <select id="assigned_team_id" name="assigned_team_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="">Unassigned</option>
                        @foreach($assignableTeams as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('assigned_team_id', $contact->assigned_team_id) === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('assigned_team_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-ui.button type="submit">Update ownership</x-ui.button>
        </form>
    @endif
</div>