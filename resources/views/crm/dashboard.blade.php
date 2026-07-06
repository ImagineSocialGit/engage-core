<x-layouts.crm :title="$title" :heading="$heading" :subheading="$subheading">
    @php
        $attentionCount = (int) ($summary['attention_count'] ?? 0);
        $taskCount = (int) ($summary['task_count'] ?? 0);
        $leadReplyCount = (int) ($summary['lead_replies'] ?? 0);
        $webinarActivityCount = (int) ($summary['webinar_activity'] ?? 0);
        $upcomingTaskSummary = $upcomingTaskSummary ?? null;
        $upcomingWeekTaskCount = (int) ($upcomingTaskSummary['count'] ?? 0);

        $badgeClasses = [
            'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
            'emerald' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        ];

        $jumpCardClass = 'w-full rounded-xl bg-white p-3 text-left ring-1 ring-slate-200 transition hover:bg-slate-50 hover:ring-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-300';
        $panelClass = 'transition duration-700 ease-out';
    @endphp

    <div
        class="space-y-6"
        x-data="{
            focusedPanel: null,
            jumpTo(panel) {
                const target = this.$refs[panel];

                if (! target) {
                    return;
                }

                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this.focusedPanel = panel;

                window.setTimeout(() => {
                    if (this.focusedPanel === panel) {
                        this.focusedPanel = null;
                    }
                }, 1600);
            },
        }"
    >
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:p-8">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">
                        Today
                    </div>

                    <h2 class="mt-3 max-w-3xl text-3xl font-semibold tracking-tight text-slate-950">
                        You have a clear place to start.
                    </h2>

                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Handle today’s tasks first, then review the {{ config('contacts.labels.plural') }} that need a human response. Webinar activity is here for context when you need it.
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @if(filled($primaryAction['href'] ?? null))
                            <a
                                href="{{ $primaryAction['href'] }}"
                                class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-800"
                            >
                                {{ $primaryAction['label'] ?? 'Start here' }}
                            </a>
                        @endif

                        @if(filled($primaryAction['summary'] ?? null))
                            <p class="text-sm font-medium text-slate-500">
                                {{ $primaryAction['summary'] }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-sm font-semibold text-slate-900">
                        Right now
                    </p>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <button type="button" class="{{ $jumpCardClass }}" @click="jumpTo('tasksPanel')">
                            <div class="text-2xl font-semibold text-slate-950">{{ $attentionCount }}</div>
                            <div class="mt-1 text-xs font-medium text-slate-500">need attention</div>
                        </button>

                        <button type="button" class="{{ $jumpCardClass }}" @click="jumpTo('tasksPanel')">
                            <div class="text-2xl font-semibold text-slate-950">{{ $taskCount }}</div>
                            <div class="mt-1 text-xs font-medium text-slate-500">tasks due/overdue</div>
                        </button>

                        <button type="button" class="{{ $jumpCardClass }}" @click="jumpTo('leadsPanel')">
                            <div class="text-2xl font-semibold text-slate-950">{{ $leadReplyCount }}</div>
                            <div class="mt-1 text-xs font-medium text-slate-500">{{ config('contacts.labels.singular') }} replies</div>
                        </button>

                        <button type="button" class="{{ $jumpCardClass }}" @click="jumpTo('webinarsPanel')">
                            <div class="text-2xl font-semibold text-slate-950">{{ $webinarActivityCount }}</div>
                            <div class="mt-1 text-xs font-medium text-slate-500">webinar updates</div>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
            <div
                x-ref="tasksPanel"
                class="{{ $panelClass }} rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:p-7"
                :class="focusedPanel === 'tasksPanel' ? 'scale-[1.01] !bg-slate-100 ring-2 ring-slate-400' : 'ring-1 ring-transparent'"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                            Today’s tasks
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Overdue, due-today, and undated open tasks. Future dated tasks stay out of this list.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if((int) ($summary['overdue_tasks'] ?? 0) > 0)
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">
                                {{ (int) ($summary['overdue_tasks'] ?? 0) }} overdue
                            </span>
                        @endif

                        @if(module_enabled('tasks'))
                            <a
                                href="{{ route('crm.tasks.today.print') }}"
                                target="_blank"
                                class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                Print
                            </a>

                            @if($canBroadcastTaskList ?? false)
                                <form method="POST" action="{{ route('crm.tasks.today.broadcast') }}">
                                    @csrf

                                    <button
                                        type="submit"
                                        class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        Broadcast
                                    </button>
                                </form>
                            @else
                                <span class="rounded-xl border border-dashed border-slate-200 px-3 py-2 text-xs font-semibold text-slate-400">
                                    Broadcast unavailable
                                </span>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse($taskItems as $task)
                        <div
                            class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 transition duration-500"
                            :class="focusedPanel === 'tasksPanel' ? 'scale-[1.005] !bg-slate-200 ring-slate-400' : ''"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $badgeClasses[$task['tone'] ?? 'slate'] ?? $badgeClasses['slate'] }}">
                                        {{ $task['label'] ?? 'Task' }}
                                    </span>

                                    <p class="mt-3 font-semibold text-slate-950">
                                        {{ $task['title'] }}
                                    </p>

                                    @if(filled($task['subtitle'] ?? null))
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ $task['subtitle'] }}
                                        </p>
                                    @endif

                                    @if(filled($task['description'] ?? null))
                                        <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                                            {{ $task['description'] }}
                                        </p>
                                    @endif
                                </div>

                                @if(filled($task['href'] ?? null))
                                    <a href="{{ $task['href'] }}?activity_tab=tasks" class="shrink-0 text-xs font-bold text-slate-700 underline underline-offset-4 hover:text-slate-950">
                                        View
                                    </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center">
                            <p class="font-semibold text-slate-950">
                                No tasks need your attention today.
                            </p>
                            <p class="mt-1 text-sm text-slate-500">
                                The manual follow-up list is clear.
                            </p>
                        </div>
                    @endforelse
                </div>

                @if(module_enabled('tasks') && $upcomingWeekTaskCount > 0)
                    <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-950">
                                    Upcoming this week
                                </p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $upcomingWeekTaskCount }} {{ str('task')->plural($upcomingWeekTaskCount) }} due after today.
                                </p>
                            </div>

                            <form method="POST" action="{{ route('crm.dashboard.acknowledgements.store') }}">
                                @csrf
                                <input type="hidden" name="item_type" value="{{ $upcomingTaskSummary['type'] }}">
                                <input type="hidden" name="item_key" value="{{ $upcomingTaskSummary['key'] }}">
                                <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                <button type="submit" class="text-xs font-bold text-slate-600 underline underline-offset-4 hover:text-slate-950">
                                    Review later
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                @if(module_enabled('tasks') && ! ($canBroadcastTaskList ?? false))
                    <p class="mt-4 text-xs leading-5 text-slate-400">
                        Broadcast becomes available when Team Members, Internal Notifications, and Messaging are enabled. Until then, use Print or View.
                    </p>
                @endif
            </div>

            <div
                x-ref="leadsPanel"
                class="{{ $panelClass }} rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:p-7"
                :class="focusedPanel === 'leadsPanel' ? 'scale-[1.01] !bg-slate-100 ring-2 ring-slate-400' : 'ring-1 ring-transparent'"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                            {{ str(config('contacts.labels.plural'))->title() }} needing attention
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Recent replies that may need a human response.
                        </p>
                    </div>

                    <a
                        href="{{ route('crm.contacts.index') }}"
                        class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                    >
                        View all {{ config('contacts.labels.plural') }}
                    </a>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse($leadItems as $lead)
                        <div
                            class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 transition duration-500"
                            :class="focusedPanel === 'leadsPanel' ? 'scale-[1.005] !bg-slate-200 ring-slate-400' : ''"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $badgeClasses[$lead['tone'] ?? 'slate'] ?? $badgeClasses['slate'] }}">
                                        {{ $lead['label'] ?? 'Needs attention' }}
                                    </span>

                                    <h3 class="mt-3 font-semibold text-slate-950">
                                        {{ $lead['title'] }}
                                    </h3>

                                    @if(filled($lead['subtitle'] ?? null))
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ $lead['subtitle'] }}
                                        </p>
                                    @endif

                                    @if(filled($lead['description'] ?? null))
                                        <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                                            {{ $lead['description'] }}
                                        </p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    @if(filled($lead['href'] ?? null))
                                        <a href="{{ $lead['href'] }}" class="text-xs font-bold text-slate-700 underline underline-offset-4 hover:text-slate-950">
                                            Review reply
                                        </a>
                                    @endif

                                    <form method="POST" action="{{ route('crm.dashboard.acknowledgements.store') }}">
                                        @csrf
                                        <input type="hidden" name="item_type" value="{{ $lead['type'] }}">
                                        <input type="hidden" name="item_key" value="{{ $lead['key'] }}">
                                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                        <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-4 hover:text-slate-800">
                                            Clear
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center">
                            <p class="font-semibold text-slate-950">
                                No {{ config('contacts.labels.singular') }} replies need review.
                            </p>
                            <p class="mt-1 text-sm text-slate-500">
                                New replies will show here when a {{ config('contacts.labels.singular') }} needs a human response.
                            </p>
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section
            x-ref="webinarsPanel"
            class="{{ $panelClass }} rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:p-7"
            :class="focusedPanel === 'webinarsPanel' ? 'scale-[1.01] !bg-slate-100 ring-2 ring-slate-400' : 'ring-1 ring-transparent'"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                        Webinar activity
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Supporting context for recent registrations. This is not the main daily triage list.
                    </p>
                </div>

                @if(module_enabled('webinars'))
                    <a
                        href="{{ route('crm.webinar-series.index') }}"
                        class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                    >
                        View webinars
                    </a>
                @endif
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @forelse($webinarItems as $activity)
                    <div
                        class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 transition duration-500"
                        :class="focusedPanel === 'webinarsPanel' ? 'scale-[1.005] !bg-slate-200 ring-slate-400' : ''"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $badgeClasses[$activity['tone'] ?? 'slate'] ?? $badgeClasses['slate'] }}">
                                    {{ $activity['label'] ?? 'Webinar' }}
                                </span>

                                <h3 class="mt-3 font-semibold text-slate-950">
                                    {{ $activity['title'] }}
                                </h3>

                                @if(filled($activity['subtitle'] ?? null))
                                    <p class="mt-1 text-sm text-slate-600">
                                        {{ $activity['subtitle'] }}
                                    </p>
                                @endif

                                @if(filled($activity['description'] ?? null))
                                    <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                                        {{ $activity['description'] }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-2">
                                @if(filled($activity['href'] ?? null))
                                    <a href="{{ $activity['href'] }}" class="text-xs font-bold text-slate-700 underline underline-offset-4 hover:text-slate-950">
                                        Open
                                    </a>
                                @endif

                                <form method="POST" action="{{ route('crm.dashboard.acknowledgements.store') }}">
                                    @csrf
                                    <input type="hidden" name="item_type" value="{{ $activity['type'] }}">
                                    <input type="hidden" name="item_key" value="{{ $activity['key'] }}">
                                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                    <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-4 hover:text-slate-800">
                                        Hide
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-200 p-6 lg:col-span-2">
                        <p class="font-semibold text-slate-950">
                            No new webinar activity to review.
                        </p>
                        <p class="mt-1 text-sm text-slate-500">
                            Recent signups will appear here as context beneath the main work panels.
                        </p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>
