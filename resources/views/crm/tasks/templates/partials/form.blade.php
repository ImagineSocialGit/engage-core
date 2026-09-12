<form method="POST" action="{{ $formAction }}" class="space-y-6" x-data="{ timing: @js(old('due_timing', $timingState['mode'])), assignment: @js(old('assignment_mode', $assignmentState['mode'])) }">
    @csrf
    @if($formMethod !== 'POST')
        @method($formMethod)
    @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <label for="template-name" class="text-sm font-semibold text-slate-900">Template name</label>
                <input id="template-name" name="name" type="text" value="{{ old('name', $taskTemplate->name) }}" required class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="template-title" class="text-sm font-semibold text-slate-900">Task title</label>
                <input id="template-title" name="title" type="text" value="{{ old('title', $taskTemplate->title) }}" required class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                @error('title')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div class="lg:col-span-2">
                <label for="template-description" class="text-sm font-semibold text-slate-900">Template purpose</label>
                <textarea id="template-description" name="description" rows="3" class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">{{ old('description', $taskTemplate->description) }}</textarea>
            </div>
            <div class="lg:col-span-2">
                <label for="template-task-description" class="text-sm font-semibold text-slate-900">Instructions shown on created Tasks</label>
                <textarea id="template-task-description" name="task_description" rows="5" class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">{{ old('task_description', $taskTemplate->task_description) }}</textarea>
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
        <h2 class="text-xl font-semibold text-slate-950">Timing and responsibility</h2>
        <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="template-priority" class="text-sm font-semibold text-slate-900">Priority</label>
                <select id="template-priority" name="priority" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="">Normal</option>
                    @foreach(['low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('priority', $taskTemplate->priority) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="due-timing" class="text-sm font-semibold text-slate-900">Due</label>
                <select id="due-timing" name="due_timing" x-model="timing" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="none">No automatic due date</option>
                    <option value="immediately">Immediately</option>
                    <option value="after">After creation</option>
                </select>
                <div class="mt-2 grid grid-cols-2 gap-2" x-show="timing === 'after'">
                    <input name="due_offset_value" type="number" min="1" value="{{ old('due_offset_value', $timingState['value']) }}" x-bind:required="timing === 'after'" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    <select name="due_offset_unit" x-bind:required="timing === 'after'" class="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                        @foreach(['minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days', 'business_days' => 'Business days', 'weeks' => 'Weeks'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('due_offset_unit', $timingState['unit']) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @error('due_offset_value')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="template-responsible-party" class="text-sm font-semibold text-slate-900">Who needs to act?</label>
                <select id="template-responsible-party" name="responsible_party" required class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    @foreach($responsiblePartyLabels as $value => $label)
                        <option value="{{ $value }}" @selected(old('responsible_party', $taskTemplate->responsible_party) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
        <h2 class="text-xl font-semibold text-slate-950">Default assignment</h2>
        <p class="mt-2 text-sm text-slate-600">Every Task created from this template follows this rule. Client presets remain portable; choose local people and Teams here after access setup.</p>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
                <label for="assignment-mode" class="text-sm font-semibold text-slate-900">Assignment rule</label>
                <select id="assignment-mode" name="assignment_mode" x-model="assignment" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="unassigned">Leave unassigned</option>
                    <option value="user">Specific person</option>
                    <option value="team">Team queue</option>
                    <option value="team_round_robin">Round-robin Team members</option>
                </select>
            </div>
            <div x-show="assignment === 'user'">
                <label for="assigned-user" class="text-sm font-semibold text-slate-900">Person</label>
                <select id="assigned-user" name="assigned_user_id" x-bind:disabled="assignment !== 'user'" x-bind:required="assignment === 'user'" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="">Choose a person</option>
                    @foreach($users as $user)
                        <option value="{{ $user->getKey() }}" @selected((int) old('assigned_user_id', $assignmentState['user_id']) === (int) $user->getKey())>{{ $user->name ?: $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="assignment === 'team' || assignment === 'team_round_robin'">
                <label for="assigned-team" class="text-sm font-semibold text-slate-900">Team</label>
                <select id="assigned-team" name="assigned_team_id" x-bind:disabled="assignment !== 'team' && assignment !== 'team_round_robin'" x-bind:required="assignment === 'team' || assignment === 'team_round_robin'" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="">Choose a Team</option>
                    @foreach($teams as $team)
                        <option value="{{ $team->getKey() }}" @selected((int) old('assigned_team_id', $assignmentState['team_id']) === (int) $team->getKey())>{{ $team->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @error('assigned_user_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @error('assigned_team_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror

        <label class="mt-6 flex items-start gap-3 rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
            <input type="hidden" name="is_active" value="0">
            <input name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', $taskTemplate->is_active)) class="mt-1 rounded border-slate-300">
            <span><span class="block font-semibold text-slate-900">Available to automations</span><span class="text-xs text-slate-600">Inactive templates remain available for history but cannot create new automated Tasks.</span></span>
        </label>
    </section>

    <div class="flex justify-end gap-2">
        <a href="{{ route('crm.tasks.templates.index') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">Cancel</a>
        <x-ui.button type="submit">{{ $submitLabel }}</x-ui.button>
    </div>
</form>