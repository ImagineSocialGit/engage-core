<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Change the reusable Task defaults used by automations."
    module="tasks"
>
    @php
        $responsiblePartyLabels = [
            \App\Modules\Tasks\Models\Task::RESPONSIBLE_PARTY_INTERNAL => 'Internal team',
            \App\Modules\Tasks\Models\Task::RESPONSIBLE_PARTY_CONTACT => config('contacts.labels.singular', 'Contact'),
            \App\Modules\Tasks\Models\Task::RESPONSIBLE_PARTY_THIRD_PARTY => 'Third party',
            \App\Modules\Tasks\Models\Task::RESPONSIBLE_PARTY_UNKNOWN => 'Not specified',
        ];
    @endphp

    <div class="space-y-6">
        @if(session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.tasks.templates.index') }}"
                class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900"
            >
                Back to Task Templates
            </a>

            <span class="text-xs font-medium text-slate-500">{{ $taskTemplate->key }}</span>
        </div>

        <form
            method="POST"
            action="{{ route('crm.tasks.templates.update', $taskTemplate) }}"
            class="space-y-6"
        >
            @csrf
            @method('PATCH')

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <label for="template-name" class="text-sm font-semibold text-slate-900">Template name</label>
                        <input
                            id="template-name"
                            name="name"
                            type="text"
                            value="{{ old('name', $taskTemplate->name) }}"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >
                        @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="template-title" class="text-sm font-semibold text-slate-900">Task title</label>
                        <input
                            id="template-title"
                            name="title"
                            type="text"
                            value="{{ old('title', $taskTemplate->title) }}"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >
                        @error('title')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label for="template-description" class="text-sm font-semibold text-slate-900">Template purpose</label>
                        <textarea
                            id="template-description"
                            name="description"
                            rows="3"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >{{ old('description', $taskTemplate->description) }}</textarea>
                        @error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label for="template-task-description" class="text-sm font-semibold text-slate-900">Instructions shown on created Tasks</label>
                        <textarea
                            id="template-task-description"
                            name="task_description"
                            rows="5"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >{{ old('task_description', $taskTemplate->task_description) }}</textarea>
                        @error('task_description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">Timing and responsibility</h2>

                <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label for="template-priority" class="text-sm font-semibold text-slate-900">Priority</label>
                        <select
                            id="template-priority"
                            name="priority"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >
                            <option value="">Normal</option>
                            @foreach(['low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('priority', $taskTemplate->priority) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('priority')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="template-due-offset" class="text-sm font-semibold text-slate-900">Due after creation</label>
                        <div class="mt-1 flex rounded-xl border border-slate-300 bg-white shadow-sm focus-within:border-slate-500 focus-within:ring-2 focus-within:ring-slate-200">
                            <input
                                id="template-due-offset"
                                name="due_offset_minutes"
                                type="number"
                                min="0"
                                max="5256000"
                                value="{{ old('due_offset_minutes', $taskTemplate->due_offset_minutes) }}"
                                class="min-w-0 flex-1 rounded-l-xl border-0 px-3 py-2.5 text-sm focus:outline-none focus:ring-0"
                            >
                            <span class="inline-flex items-center border-l border-slate-300 px-3 text-sm text-slate-600">minutes</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Use 0 for immediately. Leave blank for no automatic due date.</p>
                        @error('due_offset_minutes')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="template-responsible-party" class="text-sm font-semibold text-slate-900">Who needs to act?</label>
                        <select
                            id="template-responsible-party"
                            name="responsible_party"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        >
                            @foreach($responsiblePartyLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old('responsible_party', $taskTemplate->responsible_party) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('responsible_party')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>
                </div>

                <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current assignment</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $presented['assignment'] }}</dd>
                    </div>

                    <label class="flex items-start gap-3 rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <input type="hidden" name="is_active" value="0">
                        <input
                            name="is_active"
                            type="checkbox"
                            value="1"
                            @checked((bool) old('is_active', $taskTemplate->is_active))
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                        >
                        <span>
                            <span class="block font-semibold text-slate-900">Available to automations</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-600">Inactive templates remain visible but cannot be selected for new automation Points.</span>
                        </span>
                    </label>
                </dl>
            </section>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <a
                    href="{{ route('crm.tasks.templates.index') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50"
                >
                    Cancel
                </a>

                <x-ui.button type="submit">
                    Save Task Template
                </x-ui.button>
            </div>
        </form>
    </div>
</x-layouts.crm>