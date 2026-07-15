<x-layouts.crm
    :title="$title"
    heading="Task"
    subheading="Understand why this task exists, what needs doing, and finish it without hunting for context."
    module="tasks"
>
    @php
        $responsiblePartyLabels = [
            'internal' => 'Internal team',
            'contact' => str(config('contacts.labels.singular', 'Contact'))->title()->toString(),
            'third_party' => 'Third party',
            'unknown' => 'Unknown',
        ];

        $localDueAt = $task->due_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')));
        $taskTone = module_tone('tasks');
    @endphp

    <div class="space-y-6">
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

        <div class="flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('crm.tasks.index') }}"
                class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900"
            >
                Back to Tasks
            </a>

            <div class="flex flex-wrap gap-2">
                @if($task->archived_at)
                    <form method="POST" action="{{ route('crm.tasks.restore', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="secondary">
                            Restore
                        </x-ui.button>
                    </form>
                @elseif($task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN)
                    <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit">
                            Mark Complete
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('crm.tasks.cancel', $task) }}">
                        @csrf
                        @method('PATCH')

                        <input type="hidden" name="canceled_reason" value="Manually canceled">

                        <x-ui.button type="submit" variant="outline">
                            Cancel
                        </x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="secondary">
                            Reopen
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('crm.tasks.archive', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="outline">
                            Archive
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] {{ $taskTone['text'] ?? 'text-slate-700' }}">
                            Task
                        </p>

                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $task->status === 'open' ? 'bg-blue-50 text-blue-700' : ($task->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700') }}">
                            {{ str($task->status)->headline() }}
                        </span>

                        @if($task->archived_at)
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                Archived
                            </span>
                        @endif
                    </div>

                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ $task->title }}
                    </h2>

                    @if($task->description)
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            {{ $task->description }}
                        </p>
                    @endif
                </div>

                <div class="rounded-2xl bg-slate-50 p-4 text-sm ring-1 ring-slate-200 lg:min-w-72">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Current status
                    </div>

                    <div class="mt-1 text-lg font-semibold text-slate-950">
                        {{ str($task->status)->headline() }}
                    </div>

                    <p class="mt-2 text-slate-600">
                        @if($task->status === 'open')
                            This task is ready for action.
                        @elseif($task->status === 'completed')
                            Finished {{ $task->completed_at?->diffForHumans() ?? 'previously' }}.
                        @else
                            Canceled {{ $task->canceled_at?->diffForHumans() ?? 'previously' }}.
                        @endif
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-3">
            <x-ui.card class="space-y-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                        What
                    </p>

                    <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">
                        What needs to happen?
                    </h2>
                </div>

                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Task</dt>
                        <dd class="mt-1 font-medium text-slate-950">{{ $task->title }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-500">Helpful context</dt>
                        <dd class="mt-1 text-slate-800">{{ $task->description ?: 'No additional description.' }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-500">Due</dt>
                        <dd class="mt-1 font-medium text-slate-950">
                            {{ $localDueAt?->format('M j, Y g:i A T') ?? 'No due date' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-slate-500">Owner</dt>
                        <dd class="mt-1 font-medium text-slate-950">
                            {{ $task->assignedTo?->name ?? $task->assignedTo?->email ?? 'Unassigned' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-slate-500">Who needs to act</dt>
                        <dd class="mt-1 font-medium text-slate-950">
                            {{ $responsiblePartyLabels[$task->responsible_party] ?? str($task->responsible_party)->headline() }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                        Why
                    </p>

                    <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">
                        Why is this task here?
                    </h2>
                </div>

                @if($presentedLinks->isEmpty())
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                        This is a standalone task. It does not need another record to explain why it exists.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($presentedLinks as $link)
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            {{ $link['role_label'] }}
                                        </div>

                                        @if($link['url'])
                                            <a
                                                href="{{ $link['url'] }}"
                                                class="mt-1 inline-block font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                            >
                                                {{ $link['name'] }}
                                            </a>
                                        @else
                                            <div class="mt-1 font-semibold text-slate-950">
                                                {{ $link['name'] }}
                                            </div>
                                        @endif

                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $link['label'] }}
                                        </div>
                                    </div>
                                </div>

                                @if($link['details'])
                                    <dl class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-xs">
                                        @foreach($link['details'] as $label => $value)
                                            <div class="flex justify-between gap-4">
                                                <dt class="text-slate-500">{{ $label }}</dt>
                                                <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="border-t border-slate-200 pt-4 text-sm">
                    <div class="text-slate-500">Origin</div>
                    <div class="mt-1 font-medium text-slate-950">
                        @if($task->task_template_key)
                            Template-backed: {{ $task->task_template_key }}
                        @else
                            Manual standalone/ad hoc Task
                        @endif
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                        How
                    </p>

                    <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">
                        How do I finish it?
                    </h2>
                </div>

                @if($task->archived_at)
                    <p class="text-sm leading-6 text-slate-600">
                        Restore this task to put it back into the active workspace.
                    </p>

                    <form method="POST" action="{{ route('crm.tasks.restore', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit">
                            Restore Task
                        </x-ui.button>
                    </form>
                @elseif($task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN)
                    <p class="text-sm leading-6 text-slate-600">
                        Do the work described above, then mark the task complete. Cancel it only when the work is no longer needed.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">
                            @csrf
                            @method('PATCH')

                            <x-ui.button type="submit">
                                Mark Complete
                            </x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('crm.tasks.cancel', $task) }}">
                            @csrf
                            @method('PATCH')

                            <input type="hidden" name="canceled_reason" value="Manually canceled">

                            <x-ui.button type="submit" variant="outline">
                                Cancel Task
                            </x-ui.button>
                        </form>
                    </div>
                @else
                    <p class="text-sm leading-6 text-slate-600">
                        Reopen the task when more work is required. Archive it when you want it out of the active workspace without deleting its history.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}">
                            @csrf
                            @method('PATCH')

                            <x-ui.button type="submit">
                                Reopen Task
                            </x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('crm.tasks.archive', $task) }}">
                            @csrf
                            @method('PATCH')

                            <x-ui.button type="submit" variant="outline">
                                Archive Task
                            </x-ui.button>
                        </form>
                    </div>
                @endif
            </x-ui.card>
        </section>

        <x-ui.card>
            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                Task history
            </h2>

            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-slate-500">Created</dt>
                    <dd class="mt-1 font-medium text-slate-950">
                        {{ $task->created_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') ?? '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-slate-500">Completed</dt>
                    <dd class="mt-1 font-medium text-slate-950">
                        {{ $task->completed_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') ?? '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-slate-500">Canceled</dt>
                    <dd class="mt-1 font-medium text-slate-950">
                        {{ $task->canceled_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') ?? '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-slate-500">Source</dt>
                    <dd class="mt-1 font-medium text-slate-950">
                        {{ str($task->source)->headline() }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
</x-layouts.crm>
