
@props([
    'related' => null,
    'relatedLabel' => 'Record',
    'teamMembers' => collect(),
    'currentTeamMember' => null,
    'defaultDueAt' => null,
])

@php
    $hasTeamMembers = $teamMembers->isNotEmpty();

    $initialAssignedToId = (string) old('assigned_to_id', '');
    $currentTeamMemberId = $currentTeamMember ? (string) $currentTeamMember->id : null;

    $initialNotifyAssignee = old('notify_assignee');
    $initialDueAt = old('due_at', $defaultDueAt);

    $shouldInitiallyNotify = $initialNotifyAssignee !== null
        ? (bool) $initialNotifyAssignee
        : ($hasTeamMembers && $initialAssignedToId !== '' && $initialAssignedToId !== $currentTeamMemberId);
@endphp

<div
    x-cloak
    x-show="taskModalOpen"
    x-on:keydown.escape.window="taskModalOpen = false"
    class="fixed inset-0 z-50 flex items-center justify-center px-4"
>
    <div
        x-show="taskModalOpen"
        x-transition.opacity
        class="absolute inset-0 bg-slate-900/50"
        x-on:click="taskModalOpen = false"
    ></div>

    <div
        x-show="taskModalOpen"
        x-transition
        class="relative z-10 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl"
    >
        <div class="mb-6 flex items-center justify-between gap-4">
            <h3 class="text-lg font-semibold tracking-tight">
                Add Task
            </h3>

            <button
                type="button"
                x-on:click="taskModalOpen = false"
                class="text-slate-400 hover:text-slate-600"
            >
                ✕
            </button>
        </div>

        <form
            method="POST"
            action="{{ route('crm.tasks.store') }}"
            class="space-y-4"
            x-data="{
                assignedToId: @js($initialAssignedToId),
                currentTeamMemberId: @js($currentTeamMemberId),
                notifyAssignee: @js($shouldInitiallyNotify),
                updateNotifyAssigneeDefault() {
                    this.notifyAssignee = this.assignedToId !== ''
                        && this.assignedToId !== this.currentTeamMemberId;
                },
            }"
        >
            @csrf

            @if ($related)
                <input type="hidden" name="related_type" value="{{ $related->getMorphClass() }}">
                <input type="hidden" name="related_id" value="{{ $related->getKey() }}">

                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    This task will be attached to this {{ strtolower($relatedLabel) }}.
                </div>
            @endif

            @if ($hasTeamMembers)
                <div>
                    <x-ui.form.label for="assigned_to_id">
                        Assigned To
                    </x-ui.form.label>

                    <x-ui.form.select
                        id="assigned_to_id"
                        name="assigned_to_id"
                        x-model="assignedToId"
                        x-on:change="updateNotifyAssigneeDefault"
                    >
                        <option value="">Unassigned</option>

                        @foreach ($teamMembers as $teamMember)
                            <option
                                value="{{ $teamMember->id }}"
                                @selected((string) old('assigned_to_id') === (string) $teamMember->id)
                            >
                                {{ $teamMember->name }}
                                @if ($teamMember->email)
                                    — {{ $teamMember->email }}
                                @endif
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    <p class="mt-1 text-xs text-slate-500">
                        The assignee tracks and follows up on the task.
                    </p>

                    @error('assigned_to_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div>
                <x-ui.form.label for="responsible_party">
                    Responsible Party
                </x-ui.form.label>

                <x-ui.form.select
                    id="responsible_party"
                    name="responsible_party"
                >
                    <option value="internal" @selected(old('responsible_party', 'internal') === 'internal')>
                        Internal team
                    </option>
                    <option value="contact" @selected(old('responsible_party') === 'contact')>
                        Contact
                    </option>
                    <option value="third_party" @selected(old('responsible_party') === 'third_party')>
                        Third party
                    </option>
                    <option value="unknown" @selected(old('responsible_party') === 'unknown')>
                        Unknown
                    </option>
                </x-ui.form.select>

                <p class="mt-1 text-xs text-slate-500">
                    Who needs to do the manual thing.
                </p>

                @error('responsible_party')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <x-ui.form.label for="title">
                    Task
                </x-ui.form.label>

                <x-ui.form.input
                    id="title"
                    name="title"
                    :value="old('title')"
                />

                @error('title')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <x-ui.form.label for="description">
                    Description
                </x-ui.form.label>

                <x-ui.form.textarea
                    id="description"
                    name="description"
                    rows="3"
                >{{ old('description') }}</x-ui.form.textarea>

                @error('description')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <x-ui.form.label for="due_at">
                    Due At
                </x-ui.form.label>

                <x-ui.form.input
                    id="due_at"
                    name="due_at"
                    type="datetime-local"
                    :value="$initialDueAt"
                />

                @error('due_at')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @if ($hasTeamMembers)
                <input type="hidden" name="notify_assignee" value="0">

                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                    <input
                        type="checkbox"
                        name="notify_assignee"
                        value="1"
                        x-model="notifyAssignee"
                        class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    >

                    <span>
                        <span class="block text-sm font-semibold text-slate-900">
                            Notify assignee
                        </span>

                        <span class="block text-sm text-slate-500">
                            Send an internal task assignment notification based on the assignee’s notification preferences.
                        </span>
                    </span>
                </label>
            @endif

            <div class="flex justify-end gap-3 border-t border-slate-200 pt-4">
                <x-ui.button
                    type="button"
                    variant="outline"
                    x-on:click="taskModalOpen = false"
                >
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit">
                    Create Task
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
