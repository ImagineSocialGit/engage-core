<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="See what needs doing, why it exists, and take the next action."
    module="tasks"
>
    @php
        $taskTone = module_tone('tasks');
        $statusOptions = [
            '' => 'All statuses',
            \App\Modules\Tasks\Models\Task::STATUS_OPEN => 'Open',
            \App\Modules\Tasks\Models\Task::STATUS_COMPLETED => 'Completed',
            \App\Modules\Tasks\Models\Task::STATUS_CANCELED => 'Canceled',
        ];
    @endphp

    <div
        class="space-y-6"
        x-data="{
            taskModalOpen: @js($errors->any()),
        }"
    >
        @if(session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if(session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:p-8">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] {{ $taskTone['text'] ?? 'text-slate-700' }}">
                        Task workspace
                    </p>

                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                        Know what needs doing and why
                    </h2>

                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Tasks can stand alone or link to one or more records. Open any task to see the work, its context, and the quickest way to finish it.
                    </p>
                </div>

                <x-ui.button
                    type="button"
                    x-on:click="taskModalOpen = true"
                >
                    Add Task
                </x-ui.button>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['label' => 'Open', 'count' => $counts['open'], 'status' => 'open', 'view' => 'active'],
                ['label' => 'Completed', 'count' => $counts['completed'], 'status' => 'completed', 'view' => 'active'],
                ['label' => 'Canceled', 'count' => $counts['canceled'], 'status' => 'canceled', 'view' => 'active'],
                ['label' => 'Archived', 'count' => $counts['archived'], 'status' => null, 'view' => 'archived'],
            ] as $summary)
                <a
                    href="{{ route('crm.tasks.index', array_filter([
                        'task_view' => $summary['view'],
                        'status' => $summary['status'],
                    ], fn ($value) => $value !== null)) }}"
                    class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300"
                >
                    <div class="text-2xl font-semibold text-slate-950">
                        {{ number_format($summary['count']) }}
                    </div>

                    <div class="mt-1 text-sm font-medium text-slate-500">
                        {{ $summary['label'] }}
                    </div>
                </a>
            @endforeach
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-6">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                            {{ $taskView === 'archived' ? 'Archived tasks' : 'Active task workspace' }}
                        </h2>

                        <p class="mt-1 text-sm text-slate-600">
                            {{ $tasks->total() }} {{ str('task')->plural($tasks->total()) }} found.
                        </p>
                    </div>

                    <form
                        method="GET"
                        action="{{ route('crm.tasks.index') }}"
                        class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_12rem_auto]"
                    >
                        <input type="hidden" name="task_view" value="{{ $taskView }}">

                        <div>
                            <label for="task-search" class="text-sm font-semibold text-slate-900">
                                Search
                            </label>

                            <input
                                id="task-search"
                                name="search"
                                type="search"
                                value="{{ $search }}"
                                placeholder="Search task or description"
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                            >
                        </div>

                        <div>
                            <label for="task-status" class="text-sm font-semibold text-slate-900">
                                Status
                            </label>

                            <select
                                id="task-status"
                                name="status"
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                            >
                                @foreach($statusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(($statusFilter ?? '') === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex items-end">
                            <x-ui.button type="submit" variant="secondary">
                                Filter
                            </x-ui.button>
                        </div>
                    </form>
                </div>

                <div class="mt-4 flex gap-2 text-sm font-semibold">
                    <a
                        href="{{ route('crm.tasks.index', ['task_view' => 'active']) }}"
                        class="rounded-lg px-3 py-2 {{ $taskView === 'active' ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
                    >
                        Active
                    </a>

                    <a
                        href="{{ route('crm.tasks.index', ['task_view' => 'archived']) }}"
                        class="rounded-lg px-3 py-2 {{ $taskView === 'archived' ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
                    >
                        Archived
                    </a>
                </div>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse($tasks as $task)
                    @php
                        $links = collect($presentedLinks->get($task->getKey(), collect()));
                        $primaryLink = $links->first();
                        $dueAt = $task->due_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')));
                    @endphp

                    <article class="p-6">
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        href="{{ route('crm.tasks.show', $task) }}"
                                        class="text-lg font-semibold tracking-tight text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                    >
                                        {{ $task->title }}
                                    </a>

                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $task->status === 'open' ? 'bg-blue-50 text-blue-700' : ($task->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700') }}">
                                        {{ str($task->status)->headline() }}
                                    </span>

                                    @if($task->archived_at)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                            Archived
                                        </span>
                                    @endif
                                </div>

                                @if($task->description)
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                        {{ $task->description }}
                                    </p>
                                @endif

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Why</div>
                                        <div class="mt-1 font-medium text-slate-900">
                                            {{ $primaryLink['name'] ?? 'Standalone task' }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Due</div>
                                        <div class="mt-1 font-medium text-slate-900">
                                            {{ $dueAt?->format('M j, Y g:i A') ?? 'No due date' }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Owner</div>
                                        <div class="mt-1 font-medium text-slate-900">
                                            {{ $task->assignedTo?->name ?? $task->assignedTo?->email ?? 'Unassigned' }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Origin</div>
                                        <div class="mt-1 font-medium text-slate-900">
                                            {{ $task->task_template_key ? 'Template: '.$task->task_template_key : str($task->source)->headline() }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="shrink-0">
                                <x-ui.button
                                    href="{{ route('crm.tasks.show', $task) }}"
                                    variant="secondary"
                                >
                                    Open Task
                                </x-ui.button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center">
                        <h3 class="font-semibold text-slate-950">
                            No tasks match this view
                        </h3>

                        <p class="mt-2 text-sm text-slate-600">
                            Change the filters or add a standalone task to capture work that needs attention.
                        </p>
                    </div>
                @endforelse
            </div>

            @if($tasks->hasPages())
                <div class="border-t border-slate-200 p-6">
                    {{ $tasks->links() }}
                </div>
            @endif
        </section>

        <x-tasks.create-task-modal
            :assignee-options="$taskAssigneeOptions"
            :current-assignee-key="$currentTaskAssigneeKey"
            :default-due-at="$defaultTaskDueAt"
        />
    </div>
</x-layouts.crm>
