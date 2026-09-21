<x-layouts.crm
    :title="$title"
    heading="Task"
    :subheading="null"
    module="tasks"
>
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

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.tasks.index') }}"
                class="text-sm font-semibold text-slate-600 hover:text-slate-950"
            >
                ← Back to Tasks
            </a>

            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap">
                @if($task->archived_at)
                    <form method="POST" action="{{ route('crm.tasks.restore', $task) }}" class="col-span-2 sm:w-auto">
                        @csrf
                        @method('PATCH')
                        <x-ui.button type="submit" variant="secondary" class="w-full sm:w-auto">
                            Restore
                        </x-ui.button>
                    </form>
                @elseif($task->status === \App\Modules\Tasks\Models\Task::STATUS_OPEN)
                    <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">
                        @csrf
                        @method('PATCH')
                        <x-ui.button type="submit" class="w-full sm:w-auto">
                            Mark Complete
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('crm.tasks.cancel', $task) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="canceled_reason" value="Manually canceled">
                        <x-ui.button type="submit" variant="outline" class="w-full sm:w-auto">
                            Cancel
                        </x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}">
                        @csrf
                        @method('PATCH')
                        <x-ui.button type="submit" variant="secondary" class="w-full sm:w-auto">
                            Reopen
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('crm.tasks.archive', $task) }}">
                        @csrf
                        @method('PATCH')
                        <x-ui.button type="submit" variant="outline" class="w-full sm:w-auto">
                            Archive
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </div>

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
                <p class="mt-3 max-w-4xl text-sm leading-6 text-slate-600">
                    {{ $task->description }}
                </p>
            @endif

            <div class="mt-5 border-t border-slate-200 pt-5 text-sm leading-6 text-slate-600">
                @if($origin['kind'] === 'manual')
                    Created manually.
                @else
                    Created automatically
                    @if($origin['route_label'])
                        by
                        @if($origin['route_url'])
                            <a href="{{ $origin['route_url'] }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                                {{ $origin['route_label'] }}
                            </a>
                        @else
                            <strong class="font-semibold text-slate-900">{{ $origin['route_label'] }}</strong>
                        @endif
                    @elseif($origin['template_label'])
                        from
                        @if($origin['template_url'])
                            <a href="{{ $origin['template_url'] }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                                {{ $origin['template_label'] }}
                            </a>
                        @else
                            <strong class="font-semibold text-slate-900">{{ $origin['template_label'] }}</strong>
                        @endif
                    @endif
                    @if($origin['contact'])
                        when
                        <a href="{{ $origin['contact']['url'] }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                            {{ $origin['contact']['name'] }}
                        </a>
                    @endif
                    @if($origin['status_label'])
                        with status <strong class="font-semibold text-slate-900">{{ $origin['status_label'] }}</strong>
                    @endif
                    @if($origin['reply_summary'] && $origin['inbound'])
                        replied
                        <a href="{{ $origin['inbound']['url'] }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                            “{{ $origin['reply_summary'] }}”
                        </a>
                    @endif
                    @if($origin['outbound'])
                        to
                        @if($origin['outbound']['url'])
                            <a href="{{ $origin['outbound']['url'] }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                                {{ $origin['outbound']['name'] }}
                            </a>
                        @else
                            <strong class="font-semibold text-slate-900">{{ $origin['outbound']['name'] }}</strong>
                        @endif
                    @endif.

                    @if($origin['missing_provenance'])
                        <span class="mt-2 block text-amber-700">
                            This older task did not store which automation route created it.
                        </span>
                    @endif
                @endif
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(19rem,0.8fr)]">
            <div class="space-y-6">
                @if($inboundContext || $outboundContext)
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                                Conversation
                            </h2>

                            @if($inboundContext)
                                <a href="{{ $inboundContext['url'] }}" class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-950">
                                    Open reply
                                </a>
                            @endif
                        </div>

                        <div class="mt-5 space-y-4">
                            @if($outboundContext)
                                <article class="mr-0 rounded-2xl bg-slate-100 p-4 sm:mr-10 sm:p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                                                Message sent
                                            </p>
                                            <h3 class="mt-1 font-semibold text-slate-950">
                                                {{ $outboundContext['name'] }}
                                            </h3>
                                        </div>
                                        <p class="text-xs text-slate-500">
                                            {{ $outboundContext['channel'] ?? 'Message' }}
                                            @if(filled($outboundContext['occurred_at_label'] ?? null))
                                                · {{ $outboundContext['occurred_at_label'] }}
                                            @endif
                                        </p>
                                    </div>

                                    <p class="mt-4 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">
                                        {{ $outboundContext['message'] ?: 'The original message content is not available.' }}
                                    </p>
                                </article>
                            @else
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The reply is linked, but the message it answered was not correlated.
                                </div>
                            @endif

                            @if($inboundContext)
                                <article class="ml-0 rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:ml-10 sm:p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wide text-blue-700">
                                                Reply received
                                            </p>
                                            <h3 class="mt-1 font-semibold text-slate-950">
                                                {{ $contactContext['name'] ?? $inboundContext['name'] }}
                                            </h3>
                                        </div>
                                        <p class="text-xs text-slate-500">
                                            {{ $inboundContext['details']['Channel'] ?? 'Message' }}
                                            @if(filled($inboundContext['details']['Received'] ?? null))
                                                · {{ $inboundContext['details']['Received'] }}
                                            @endif
                                        </p>
                                    </div>

                                    <p class="mt-4 whitespace-pre-wrap break-words text-base leading-7 text-slate-900">
                                        {{ $inboundContext['message'] ?: 'No reply text was provided.' }}
                                    </p>
                                </article>
                            @endif
                        </div>
                    </section>
                @elseif($origin['kind'] === 'automation')
                    <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-7">
                        <h2 class="font-semibold text-amber-950">
                            The triggering message is not linked
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-amber-900">
                            This task was created automatically, but its source reply was not saved with the task. The automation configuration needs attention before this task can provide useful context.
                        </p>
                    </section>
                @endif

                @if($contactContext)
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                                    {{ config('contacts.labels.singular', 'Contact') }}
                                </p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950">
                                    {{ $contactContext['name'] }}
                                </h2>
                            </div>

                            <a href="{{ $contactContext['url'] }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950">
                                Open profile
                            </a>
                        </div>

                        <dl class="mt-5 grid gap-4 border-t border-slate-200 pt-5 text-sm sm:grid-cols-2">
                            @foreach($contactContext['details'] as $label => $value)
                                <div>
                                    <dt class="text-slate-500">{{ $label }}</dt>
                                    <dd class="mt-1 break-words font-medium text-slate-950">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
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
                                            {{ $link['name'] }}
                                        </a>
                                    @else
                                        <p class="mt-1 font-semibold text-slate-950">{{ $link['name'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                        Details
                    </h2>

                    <dl class="mt-4 space-y-4 text-sm">
                        <div>
                            <dt class="text-slate-500">Due</dt>
                            <dd class="mt-1 font-medium text-slate-950">
                                {{ $localDueAt?->format('M j, Y g:i A T') ?? 'No due date' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Assigned to</dt>
                            <dd class="mt-1 font-medium text-slate-950">
                                {{ $task->assignedTo?->name ?? $task->assignedTo?->email ?? 'Unassigned' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Responsible party</dt>
                            <dd class="mt-1 font-medium text-slate-950">
                                {{ $responsiblePartyLabels[$task->responsible_party] ?? str($task->responsible_party)->headline() }}
                            </dd>
                        </div>
                    </dl>

                    <form method="POST" action="{{ route('crm.tasks.assignment.update', $task) }}" class="mt-5 space-y-3 border-t border-slate-200 pt-5">
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

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                        Activity
                    </h2>

                    <dl class="mt-4 space-y-4 text-sm">
                        <div>
                            <dt class="text-slate-500">Created</dt>
                            <dd class="mt-1 font-medium text-slate-950">
                                {{ $task->created_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') ?? '—' }}
                            </dd>
                        </div>
                        @if($task->completed_at)
                            <div>
                                <dt class="text-slate-500">Completed</dt>
                                <dd class="mt-1 font-medium text-slate-950">
                                    {{ $task->completed_at->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') }}
                                </dd>
                            </div>
                        @endif
                        @if($task->canceled_at)
                            <div>
                                <dt class="text-slate-500">Canceled</dt>
                                <dd class="mt-1 font-medium text-slate-950">
                                    {{ $task->canceled_at->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A T') }}
                                </dd>
                            </div>
                        @endif
                        @if($task->canceled_reason)
                            <div>
                                <dt class="text-slate-500">Reason</dt>
                                <dd class="mt-1 font-medium text-slate-950">{{ $task->canceled_reason }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.crm>