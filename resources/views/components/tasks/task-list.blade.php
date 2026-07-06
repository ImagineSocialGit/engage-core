
@props([
    'tasks',
    'archivedTasks' => collect(),
    'taskView' => 'active',
])

@php
    $showingArchived = $taskView === 'archived';
    $visibleTasks = $showingArchived
        ? collect($archivedTasks)->values()
        : collect($tasks)
            ->sortBy(fn ($task) => sprintf(
                '%d-%012d-%012d-%012d',
                $task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN ? 0 : 1,
                $task->due_at ? 0 : 1,
                $task->due_at?->timestamp ?? 999999999999,
                $task->id ?? 0,
            ))
            ->values();

    $responsiblePartyLabels = [
        'internal' => 'Internal team',
        'contact' => config('contacts.labels.singular'),
        'third_party' => 'Third party',
        'unknown' => 'Unknown',
    ];
@endphp

<div class="flex items-center justify-between gap-4">
    <div class="flex rounded-xl bg-slate-100 p-1 text-sm font-semibold">
        <a
            href="{{ request()->fullUrlWithQuery(['task_view' => 'active', 'activity_tab' => 'tasks']) }}"
            class="rounded-lg px-3 py-1.5 {{ ! $showingArchived ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}"
        >
            Active
        </a>

        <a
            href="{{ request()->fullUrlWithQuery(['task_view' => 'archived', 'activity_tab' => 'tasks']) }}"
            class="rounded-lg px-3 py-1.5 {{ $showingArchived ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}"
        >
            Archived
        </a>
    </div>

    <p class="text-sm text-slate-500">
        {{ $visibleTasks->count() }} {{ str('task')->plural($visibleTasks->count()) }}
    </p>
</div>

<div class="space-y-3 border-t border-slate-200 pt-4">
    @forelse ($visibleTasks as $task)
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="font-semibold text-slate-900">
                        {{ $task->title }}
                    </p>

                    <div class="mt-2 grid gap-2 text-sm text-slate-500 sm:grid-cols-2">
                        <p>
                            Owner:
                            <span class="font-medium text-slate-700">
                                {{ $task->assignedTo?->name ?? 'Unassigned' }}
                            </span>
                        </p>

                        <p>
                            Who needs to act:
                            <span class="font-medium text-slate-700 capitalize">
                                {{ $responsiblePartyLabels[$task->responsible_party] ?? str((string) $task->responsible_party)->replace('_', ' ')->title() }}
                            </span>
                        </p>
                    </div>
                </div>

                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $task->status === 'completed' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700' }}">
                    {{ str($task->status)->replace('_', ' ')->title() }}
                </span>
            </div>

            @if ($task->description)
                <p class="mt-3 text-sm text-slate-700">
                    {{ $task->description }}
                </p>
            @endif

            <p class="mt-3 text-xs text-slate-500">
                Due:
                <span class="font-medium text-slate-700">
                    {{ $task->due_at?->format('M j, Y g:i A') ?? 'No due date' }}
                </span>
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($task->archived_at)
                    <form method="POST" action="{{ route('crm.tasks.restore', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="secondary">
                            Restore
                        </x-ui.button>
                    </form>
                @elseif ($task->status === 'open')
                    <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="secondary">
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

                        <x-ui.button type="submit" variant="outline">
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
    @empty
        <div class="rounded-xl border border-dashed border-slate-200 p-6 text-center">
            <p class="text-sm font-medium text-slate-900">
                {{ $showingArchived ? 'No archived tasks.' : 'No active tasks.' }}
            </p>

            @unless($showingArchived)
                <p class="mt-1 text-sm text-slate-500">
                    There is no manual follow-up needed right now.
                </p>
            @endunless
        </div>
    @endforelse
</div>
