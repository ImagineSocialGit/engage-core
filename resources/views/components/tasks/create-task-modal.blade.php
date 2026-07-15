@props([
    'subject' => null,
    'subjectLabel' => 'Record',
    'assigneeOptions' => collect(),
    'currentAssigneeKey' => null,
    'defaultDueAt' => null,
])

@php
    $assigneeOptions = collect($assigneeOptions)->values();
    $hasAssigneeOptions = $assigneeOptions->isNotEmpty();

    $assigneeMap = $assigneeOptions
        ->mapWithKeys(fn ($option) => [
            $option->key() => [
                'type' => $option->assignee->getMorphClass(),
                'id' => (int) $option->assignee->getKey(),
            ],
        ])
        ->all();

    $oldAssignedToType = old('assigned_to_type');
    $oldAssignedToId = old('assigned_to_id');

    $initialAssignedToKey = $assigneeOptions
        ->first(fn ($option) =>
            $oldAssignedToType !== null
            && $oldAssignedToId !== null
            && $option->assignee->getMorphClass() === $oldAssignedToType
            && (string) $option->assignee->getKey() === (string) $oldAssignedToId
        )
        ?->key() ?? '';

    $initialNotifyAssignee = old('notify_assignee');
    $initialDueAt = old('due_at', $defaultDueAt);

    $shouldInitiallyNotify = $initialNotifyAssignee !== null
        ? (bool) $initialNotifyAssignee
        : ($initialAssignedToKey !== '' && $initialAssignedToKey !== $currentAssigneeKey);
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
            <div>
                <h3 class="text-lg font-semibold tracking-tight">
                    Add Task
                </h3>

                <p class="mt-1 text-sm text-slate-500">
                    Capture exactly what needs doing. Leave the task unlinked when it stands on its own.
                </p>
            </div>

            <button
                type="button"
                x-on:click="taskModalOpen = false"
                class="text-slate-400 hover:text-slate-600"
                aria-label="Close task form"
            >
                ✕
            </button>
        </div>

        <form
            method="POST"
            action="{{ route('crm.tasks.store') }}"
            class="space-y-4"
            x-data="{
                assignedToKey: @js($initialAssignedToKey),
                currentAssigneeKey: @js($currentAssigneeKey),
                assigneeMap: @js($assigneeMap),
                assignedToType: '',
                assignedToId: '',
                notifyAssignee: @js($shouldInitiallyNotify),
                syncAssignment() {
                    const selected = this.assigneeMap[this.assignedToKey] || null;
                    this.assignedToType = selected?.type || '';
                    this.assignedToId = selected?.id || '';
                },
                updateNotifyAssigneeDefault() {
                    this.notifyAssignee = this.assignedToKey !== ''
                        && this.assignedToKey !== this.currentAssigneeKey;
                },
                init() {
                    this.syncAssignment();
                },
            }"
        >
            @csrf

            @if ($subject)
                <input type="hidden" name="links[0][role]" value="subject">
                <input type="hidden" name="links[0][linkable_type]" value="{{ $subject->getMorphClass() }}">
                <input type="hidden" name="links[0][linkable_id]" value="{{ $subject->getKey() }}">

                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    Why this task is here:
                    <span class="font-semibold text-slate-800">
                        linked to this {{ strtolower($subjectLabel) }}.
                    </span>
                </div>
            @else
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    This will be a standalone task. It can still be linked to other records later.
                </div>
            @endif

            @if ($hasAssigneeOptions)
                <div>
                    <x-ui.form.label for="task_assignee">
                        Assigned To
                    </x-ui.form.label>

                    <x-ui.form.select
                        id="task_assignee"
                        x-model="assignedToKey"
                        x-on:change="syncAssignment(); updateNotifyAssigneeDefault()"
                    >
                        <option value="">Unassigned</option>

                        @foreach ($assigneeOptions as $option)
                            <option value="{{ $option->key() }}">
                                {{ $option->label }}
                                @if ($option->description)
                                    — {{ $option->description }}
                                @endif
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    <input
                        type="hidden"
                        name="assigned_to_type"
                        x-bind:value="assignedToType"
                    >

                    <input
                        type="hidden"
                        name="assigned_to_id"
                        x-bind:value="assignedToId"
                    >

                    <p class="mt-1 text-xs text-slate-500">
                        The assignee owns follow-up. Tasks still work when no optional assignee provider is enabled.
                    </p>

                    <x-ui.form.error name="assigned_to_id" />
                </div>
            @endif

            <div>
                <x-ui.form.label for="responsible_party">
                    Who Needs to Act?
                </x-ui.form.label>

                <x-ui.form.select
                    id="responsible_party"
                    name="responsible_party"
                >
                    <option value="internal" @selected(old('responsible_party', 'internal') === 'internal')>
                        Internal team
                    </option>
                    <option value="contact" @selected(old('responsible_party') === 'contact')>
                        {{ str(config('contacts.labels.singular', 'Contact'))->title() }}
                    </option>
                    <option value="third_party" @selected(old('responsible_party') === 'third_party')>
                        Third party
                    </option>
                    <option value="unknown" @selected(old('responsible_party') === 'unknown')>
                        Unknown
                    </option>
                </x-ui.form.select>

                <p class="mt-1 text-xs text-slate-500">
                    This describes who must do the work. It is separate from who owns follow-up.
                </p>

                <x-ui.form.error name="responsible_party" />
            </div>

            <div>
                <x-ui.form.label for="title">
                    What Needs to Be Done?
                </x-ui.form.label>

                <x-ui.form.input
                    id="title"
                    name="title"
                    :value="old('title')"
                    required
                />

                <x-ui.form.error name="title" />
            </div>

            <div>
                <x-ui.form.label for="description">
                    Helpful Context
                </x-ui.form.label>

                <x-ui.form.textarea
                    id="description"
                    name="description"
                    rows="3"
                >{{ old('description') }}</x-ui.form.textarea>

                <x-ui.form.error name="description" />
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

                <x-ui.form.error name="due_at" />
            </div>

            @if ($hasAssigneeOptions)
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
                            Use the available Task notification integration and the assignee’s notification preferences.
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
