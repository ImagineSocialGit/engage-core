<x-layouts.crm
    :title="$title"
    heading="Task"
    :subheading="null"
    module="tasks"
>
    <div
        class="space-y-6"
        x-data="{
            messagePreviewOpen: false,
            cancelTaskOpen: false,
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

        <a
            href="{{ route('crm.tasks.index') }}"
            class="inline-flex text-sm font-semibold text-slate-600 hover:text-slate-950"
        >
            ← Back to Tasks
        </a>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(19rem,0.8fr)] xl:items-start">
            <div class="space-y-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-blue-50 text-blue-700' => $task->status === 'open',
                            'bg-emerald-50 text-emerald-700' => $task->status === 'completed',
                            'bg-slate-100 text-slate-700' => $task->status === 'canceled',
                        ])>
                            {{ str($task->status)->headline() }}
                        </span>

                        @if($task->priority)
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                {{ str($task->priority)->headline() }} priority
                            </span>
                        @endif

                        @if($task->archived_at)
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                Archived
                            </span>
                        @endif
                    </div>

                    <h1 class="mt-3 break-words text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">
                        {{ $task->title }}
                    </h1>

                    @if($task->description)
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ $task->description }}
                        </p>
                    @endif

                    <div @class([
                        'mt-5 inline-flex rounded-xl border px-3 py-2 text-sm',
                        'border-emerald-200 bg-emerald-50' => $taskContext['due_state'] === 'upcoming',
                        'border-amber-200 bg-amber-50' => $taskContext['due_state'] === 'today',
                        'border-red-200 bg-red-50' => $taskContext['due_state'] === 'overdue',
                        'border-slate-200 bg-slate-50' => $taskContext['due_state'] === null,
                    ])>
                        <div>
                            <p @class([
                                'text-xs font-semibold uppercase tracking-wide',
                                'text-emerald-700' => $taskContext['due_state'] === 'upcoming',
                                'text-amber-700' => $taskContext['due_state'] === 'today',
                                'text-red-700' => $taskContext['due_state'] === 'overdue',
                                'text-slate-500' => $taskContext['due_state'] === null,
                            ])>Due</p>
                            <p @class([
                                'mt-0.5 font-semibold',
                                'text-emerald-950' => $taskContext['due_state'] === 'upcoming',
                                'text-amber-950' => $taskContext['due_state'] === 'today',
                                'text-red-950' => $taskContext['due_state'] === 'overdue',
                                'text-slate-950' => $taskContext['due_state'] === null,
                            ])>
                                {{ $localDueAt?->format('M j, Y g:i A T') ?? 'No due date' }}
                            </p>
                        </div>
                    </div>
                </section>

                @if($contactContext)
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-medium text-slate-500">
                                    {{ config('contacts.labels.singular', 'Contact') }}
                                </p>
                                <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
                                    {{ $contactContext['name'] }}
                                </h2>
                            </div>

                            <a
                                href="{{ $contactContext['url'] }}"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950"
                            >
                                Open profile
                            </a>
                        </div>

                        <dl class="mt-5 grid gap-4 border-t border-slate-200 pt-5 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-slate-500">Email</dt>
                                <dd class="mt-1 break-words font-medium text-slate-950">
                                    {{ $contactContext['details']['Email'] ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Phone</dt>
                                <dd class="mt-1 break-words font-medium text-slate-950">
                                    {{ $contactContext['details']['Phone'] ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                @endif

                @if($inboundContext)
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                            Conversation
                        </h2>

                        <div class="mt-5 space-y-3">
                            @if($outboundContext)
                                <article class="rounded-2xl bg-slate-50 px-4 py-4 sm:px-5">
                                    <div class="flex items-center gap-4">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-700">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 4l15 8-15 8 3-8-3-8Z" />
                                                <path stroke-linecap="round" d="M8 12h7" />
                                            </svg>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm text-slate-600">You sent:</p>
                                            <button
                                                type="button"
                                                x-on:click="messagePreviewOpen = true"
                                                class="mt-0.5 max-w-full break-words text-left text-base font-semibold text-blue-700 underline decoration-blue-300 underline-offset-4 hover:text-blue-900 hover:decoration-blue-700"
                                                data-task-message-preview-open
                                            >
                                                {{ $outboundContext['conversation_label'] ?? $outboundContext['name'] }}
                                            </button>
                                        </div>

                                        <div class="shrink-0 text-right text-xs leading-5 text-slate-500">
                                            @if(filled($outboundContext['occurred_at_label'] ?? null))
                                                <p>{{ $outboundContext['occurred_at_label'] }}</p>
                                            @endif
                                            <p>{{ $outboundContext['channel'] ?? 'Message' }}</p>
                                        </div>
                                    </div>
                                </article>
                            @else
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The reply is linked, but the message it answered was not correlated.
                                </div>
                            @endif

                            @if($inboundContext)
                                <article class="rounded-2xl bg-emerald-50/70 px-4 py-4 sm:px-5">
                                    <div class="flex items-center gap-4">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7l-5 5 5 5" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h9a6 6 0 016 6" />
                                            </svg>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm text-slate-600">
                                                {{ $contactContext['name'] ?? $inboundContext['name'] }} replied:
                                            </p>
                                            <p class="mt-0.5 whitespace-pre-wrap break-words text-lg font-medium leading-7 text-slate-950">{{ $inboundContext['message'] ?: 'No reply text was provided.' }}</p>
                                        </div>

                                        <div class="shrink-0 text-right text-xs leading-5 text-slate-500">
                                            @if(filled($inboundContext['details']['Received'] ?? null))
                                                <p>{{ $inboundContext['details']['Received'] }}</p>
                                            @endif
                                            <p>{{ $inboundContext['details']['Channel'] ?? 'Message' }}</p>
                                        </div>
                                    </div>
                                </article>
                            @endif
                        </div>
                    </section>
                @endif

                @if($taskContext['other_links']->isNotEmpty())
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                            Related records
                        </h2>

                        <div class="mt-4 divide-y divide-slate-200">
                            @foreach($taskContext['other_links'] as $link)
                                <div class="py-4 first:pt-0 last:pb-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ $link['label'] }}
                                    </p>
                                    @if($link['url'])
                                        <a href="{{ $link['url'] }}" class="mt-1 inline-block font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                                            {{ $link['conversation_label'] ?? $link['name'] }}
                                        </a>
                                    @else
                                        <p class="mt-1 font-semibold text-slate-950">{{ $link['conversation_label'] ?? $link['name'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-5">
                    @if($task->archived_at)
                        <form method="POST" action="{{ route('crm.tasks.restore', $task) }}" class="sm:w-auto">
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" variant="secondary" class="w-full sm:min-w-48 sm:w-auto">
                                Restore
                            </x-ui.button>
                        </form>
                    @elseif($task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN)
                        <form method="POST" action="{{ route('crm.tasks.complete', $task) }}" class="sm:w-auto">
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" class="w-full sm:min-w-48 sm:w-auto">
                                Mark Complete
                            </x-ui.button>
                        </form>

                        <span class="hidden h-8 w-px bg-slate-300 sm:block" aria-hidden="true"></span>

                        <x-ui.button
                            type="button"
                            variant="outline"
                            class="w-full border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800 sm:min-w-40 sm:w-auto"
                            x-on:click="cancelTaskOpen = true"
                            data-task-cancel-open
                        >
                            Cancel task
                        </x-ui.button>
                    @else
                        <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}" class="sm:w-auto">
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" variant="secondary" class="w-full sm:min-w-40 sm:w-auto">
                                Reopen
                            </x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('crm.tasks.archive', $task) }}" class="sm:w-auto">
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" variant="outline" class="w-full sm:min-w-40 sm:w-auto">
                                Archive
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>

            <aside class="space-y-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                        Task assignment
                    </h2>

                    <dl class="mt-5 text-sm">
                        <div>
                            <dt class="text-slate-500">Assigned to</dt>
                            <dd class="mt-1 text-base font-medium text-slate-950">
                                {{ $task->assignedTo?->name ?? $task->assignedTo?->email ?? 'Unassigned' }}
                            </dd>
                        </div>
                    </dl>

                    <form method="POST" action="{{ route('crm.tasks.assignment.update', $task) }}" class="mt-5 space-y-3">
                        @csrf
                        @method('PATCH')
                        <label for="assignee_key" class="block text-sm font-medium text-slate-700">
                            Change assignment
                        </label>
                        <select id="assignee_key" name="assignee_key" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="">Unassigned</option>
                            @foreach($taskAssigneeOptions as $option)
                                <option value="{{ $option->key() }}" @selected(old('assignee_key', $currentTaskAssigneeKey) === $option->key())>
                                    {{ $option->label }}{{ $option->description ? ' — '.$option->description : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('assignee_key')
                            <p class="text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        <x-ui.button type="submit" variant="secondary" class="w-full">
                            Update assignment
                        </x-ui.button>
                    </form>
                </section>
            </aside>
        </div>

        <div class="border-t border-slate-200 pt-5 text-sm leading-6 text-slate-500">
            @if($origin['kind'] === 'manual')
                Created manually
                @if($origin['template_label'])
                    from
                    @if($origin['template_url'])
                        <a href="{{ $origin['template_url'] }}" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">
                            {{ $origin['template_label'] }}
                        </a>
                    @else
                        <strong class="font-semibold text-slate-700">{{ $origin['template_label'] }}</strong>
                    @endif
                @endif.
            @else
                Created automatically
                @if($origin['route_label'])
                    by
                    @if($origin['route_url'])
                        <a
                            href="{{ $origin['route_url'] }}"
                            title="{{ $origin['route_label'] }}"
                            class="font-semibold text-blue-700 underline decoration-blue-300 underline-offset-4 hover:text-blue-900 hover:decoration-blue-700"
                        >this route</a>
                    @else
                        <strong class="font-semibold text-slate-700">{{ $origin['route_label'] }}</strong>
                    @endif
                @elseif($origin['template_label'])
                    from
                    @if($origin['template_url'])
                        <a href="{{ $origin['template_url'] }}" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">
                            {{ $origin['template_label'] }}
                        </a>
                    @else
                        <strong class="font-semibold text-slate-700">{{ $origin['template_label'] }}</strong>
                    @endif
                @endif

                @if($origin['reply_summary'] && $origin['inbound'])
                    when a contact
                    @if($origin['status_label'])
                        with <strong class="font-semibold text-slate-700">{{ $origin['status_label'] }}</strong> status
                    @endif
                    replies
                    <a
                        href="{{ $origin['inbound']['url'] }}"
                        class="font-semibold text-blue-700 underline decoration-blue-300 underline-offset-4 hover:text-blue-900 hover:decoration-blue-700"
                    >“{{ $origin['reply_summary'] }}”</a>
                    @if($origin['outbound'])
                        to
                        @if(filled($origin['outbound']['template_url'] ?? null))
                            <a
                                href="{{ $origin['outbound']['template_url'] }}"
                                class="font-semibold text-blue-700 underline decoration-blue-300 underline-offset-4 hover:text-blue-900 hover:decoration-blue-700"
                            >{{ $origin['outbound']['conversation_label'] ?? $origin['outbound']['template_name'] ?? $origin['outbound']['name'] }}</a>
                        @else
                            <strong class="font-semibold text-slate-700">{{ $origin['outbound']['conversation_label'] ?? $origin['outbound']['name'] }}</strong>
                        @endif
                    @endif
                @elseif($origin['status_label'])
                    for a contact with <strong class="font-semibold text-slate-700">{{ $origin['status_label'] }}</strong> status
                @endif.
            @endif
        </div>
        @if($inboundContext && $outboundContext)
            <div
                x-show="messagePreviewOpen"
                x-cloak
                x-on:keydown.escape.window="if (messagePreviewOpen) messagePreviewOpen = false"
                x-on:click.self="messagePreviewOpen = false"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-4"
                data-task-message-preview-modal
            >
                <section
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="task-message-preview-title"
                    class="my-auto max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
                >
                    <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-500">Message sent</p>
                            <h2 id="task-message-preview-title" class="mt-1 break-words text-xl font-semibold tracking-tight text-slate-950">
                                {{ $outboundContext['conversation_label'] ?? $outboundContext['name'] }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $outboundContext['channel'] ?? 'Message' }}
                                @if(filled($outboundContext['occurred_at_label'] ?? null))
                                    · {{ $outboundContext['occurred_at_label'] }}
                                @endif
                            </p>
                        </div>

                        <button
                            type="button"
                            x-on:click="messagePreviewOpen = false"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl font-bold text-slate-600 hover:bg-slate-50 hover:text-slate-950"
                            aria-label="Close message preview"
                        >
                            ×
                        </button>
                    </div>

                    <div class="space-y-5 px-5 py-5 sm:px-6 sm:py-6">
                        @if(filled($outboundContext['subject'] ?? null))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject</p>
                                <p class="mt-1 font-medium text-slate-950">{{ $outboundContext['subject'] }}</p>
                            </div>
                        @endif

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Message</p>
                            <div class="mt-2 whitespace-pre-wrap break-words rounded-2xl bg-slate-50 p-4 text-sm leading-7 text-slate-800 sm:p-5">{{ $outboundContext['message'] ?: 'The original message content is not available.' }}</div>
                        </div>
                    </div>

                    <div class="sticky bottom-0 flex justify-end border-t border-slate-200 bg-white px-5 py-4 sm:px-6">
                        <x-ui.button type="button" variant="outline" x-on:click="messagePreviewOpen = false">
                            Close
                        </x-ui.button>
                    </div>
                </section>
            </div>
        @endif

        @if(! $task->archived_at && $task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN)
            <div
                x-show="cancelTaskOpen"
                x-cloak
                x-on:keydown.escape.window="if (cancelTaskOpen) cancelTaskOpen = false"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cancel-task-title"
                data-task-cancel-modal
            >
                <section
                    class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl"
                    x-on:click.outside="cancelTaskOpen = false"
                >
                    <h2 id="cancel-task-title" class="text-xl font-semibold tracking-tight text-slate-950">
                        Cancel this task?
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        This closes the task without marking it complete.
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <x-ui.button type="button" variant="outline" x-on:click="cancelTaskOpen = false">
                            Keep task
                        </x-ui.button>

                        <form method="POST" action="{{ route('crm.tasks.cancel', $task) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="canceled_reason" value="Manually canceled">
                            <x-ui.button
                                type="submit"
                                class="w-full bg-red-700 hover:bg-red-800 sm:w-auto"
                                data-task-cancel-confirm
                            >
                                Cancel task
                            </x-ui.button>
                        </form>
                    </div>
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>