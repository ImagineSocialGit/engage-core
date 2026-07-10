<x-layouts.crm
    title="Routes"
    heading="Routes"
    subheading="Review and change the paths your system can run automatically."
    module="flow_routes"
>
    <div class="space-y-6">
        @include('crm.flow-routes.partials.navigation')

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
            <div class="p-6 sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">
                    Manage Routes
                </p>

                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    Understand and change what happens automatically
                </h2>

                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">
                    Routes connect multiple actions, waits, and decisions into a path. Review a Route below to understand what it does and where it runs.
                </p>

                @if($routeSummary['unassigned_routes'] > 0)
                    <div class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        <span class="font-semibold">{{ $routeSummary['unassigned_routes'] }} {{ \Illuminate\Support\Str::plural('Route', $routeSummary['unassigned_routes']) }} not assigned.</span>
                        Review where {{ $routeSummary['unassigned_routes'] === 1 ? 'it should run' : 'they should run' }}.
                    </div>
                @endif
            </div>
        </section>

        <section
            class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm"
            x-data="{
                search: '',
                assignment: 'all',
                matchesRoute(element) {
                    const query = this.search.trim().toLowerCase();
                    const matchesSearch = query === '' || element.dataset.search.includes(query);
                    const matchesAssignment = this.assignment === 'all' || element.dataset.assignment === this.assignment;

                    return matchesSearch && matchesAssignment;
                },
            }"
        >
            <div class="border-b border-orange-100 p-6 sm:p-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                            Routes
                        </h2>

                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-700">
                            Multi-step paths that coordinate actions, waits, and decisions.
                        </p>
                    </div>

                    @if($routes->count() >= 5)
                        <div class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_12rem]">
                            <div>
                                <label for="route-search" class="text-sm font-semibold text-slate-900">
                                    Search Routes
                                </label>

                                <input
                                    id="route-search"
                                    type="search"
                                    x-model.debounce.200ms="search"
                                    placeholder="Search by name, trigger, or outcome"
                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm placeholder:text-slate-500 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                >
                            </div>

                            <div>
                                <label for="route-assignment-filter" class="text-sm font-semibold text-slate-900">
                                    Assignment
                                </label>

                                <select
                                    id="route-assignment-filter"
                                    x-model="assignment"
                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                >
                                    <option value="all">All Routes</option>
                                    <option value="assigned">Assigned</option>
                                    <option value="unassigned">Unassigned</option>
                                </select>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="divide-y divide-orange-100">
                @forelse($routes as $route)
                    @php
                        $assignmentUrl = route('crm.flow-routes.bindings.index', $route['assignment_query']);

                        if($route['assignment_anchor']) {
                            $assignmentUrl .= '#'.$route['assignment_anchor'];
                        }

                        $searchText = \Illuminate\Support\Str::lower(implode(' ', [
                            $route['name'],
                            $route['description'],
                            $route['trigger_summary'],
                            ...$route['summary_points'],
                        ]));
                    @endphp

                    <article
                        class="p-6 sm:p-8"
                        data-search="{{ $searchText }}"
                        data-assignment="{{ $route['assignment_count'] > 0 ? 'assigned' : 'unassigned' }}"
                        x-show="matchesRoute($el)"
                    >
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-xl font-semibold tracking-tight text-slate-950">
                                        {{ $route['name'] }}
                                    </h3>

                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $route['assignment_count'] > 0 ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-amber-50 text-amber-950 ring-amber-300' }}">
                                        {{ $route['assignment_count'] > 0 ? 'Assigned' : 'Not assigned' }}
                                    </span>

                                    @unless($route['is_active'])
                                        <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-900 ring-1 ring-slate-300">
                                            Inactive
                                        </span>
                                    @endunless
                                </div>

                                <p class="mt-3 text-sm font-semibold text-slate-900">
                                    {{ $route['trigger_summary'] }}
                                </p>

                                @if(count($route['presented_points']) > 0)
                                    <details class="group mt-5">
                                        <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-950 transition hover:bg-orange-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400">
                                            <span>{{ $route['point_count'] }} {{ \Illuminate\Support\Str::plural('Point', $route['point_count']) }}</span>
                                            <span class="text-orange-700 group-open:hidden">· Show flow</span>
                                            <span class="hidden text-orange-700 group-open:inline">· Hide flow</span>
                                        </summary>

                                        <ol class="mt-4 flex flex-col gap-2 lg:flex-row lg:items-stretch" aria-label="Route flow">
                                            @foreach($route['presented_points'] as $index => $point)
                                                <li class="flex min-w-0 items-center gap-2">
                                                    <div
                                                        class="flex h-full min-w-0 flex-1 items-start gap-3 rounded-xl px-3 py-3 text-sm text-slate-900 ring-1 {{ module_tone($point['module_key'], 'item') }}"
                                                        data-module="{{ $point['module_key'] }}"
                                                    >
                                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold ring-1 {{ module_tone($point['module_key'], 'text') }}">
                                                            {{ $index + 1 }}
                                                        </span>

                                                        <span class="min-w-0">
                                                            <span class="block font-medium">{{ $point['summary'] }}</span>

                                                            @foreach($point['condition_summaries'] as $conditionSummary)
                                                                <span class="mt-1 block text-xs leading-5 text-slate-700">
                                                                    {{ $conditionSummary }}
                                                                </span>
                                                            @endforeach
                                                        </span>
                                                    </div>

                                                    @unless($loop->last)
                                                        <span class="hidden shrink-0 text-lg font-bold text-orange-500 lg:inline" aria-hidden="true">→</span>
                                                        <span class="shrink-0 self-center text-lg font-bold text-orange-500 lg:hidden" aria-hidden="true">↓</span>
                                                    @endunless
                                                </li>
                                            @endforeach
                                        </ol>
                                    </details>
                                @elseif($route['description'])
                                    <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-700">
                                        {{ $route['description'] }}
                                    </p>
                                @else
                                    <p class="mt-5 rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                                        This Route has no active Points and needs attention.
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-2 xl:justify-end">
                                <a
                                    href="{{ $assignmentUrl }}"
                                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                                >
                                    {{ $route['assignment_count'] > 0 ? 'Review Assignment' : 'Assign Route' }}
                                </a>
                            </div>
                        </div>

                        <details class="mt-5 border-t border-orange-100 pt-4">
                            <summary class="cursor-pointer text-sm font-semibold text-slate-800 marker:text-slate-500 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300">
                                Details
                            </summary>

                            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-semibold text-slate-950">Runs when</dt>
                                    <dd class="mt-1 text-slate-700">{{ $route['trigger_summary'] }}</dd>
                                </div>

                                <div>
                                    <dt class="font-semibold text-slate-950">Source</dt>
                                    <dd class="mt-1 text-slate-700">{{ $route['source_label'] }}</dd>
                                </div>
                            </dl>
                        </details>
                    </article>
                @empty
                    <div class="p-10 text-center">
                        <h3 class="font-semibold text-slate-950">No multi-step Routes yet</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            Simple automatic actions are listed separately below. Multi-step Routes will appear here when they contain two or more active Points.
                        </p>
                    </div>
                @endforelse
            </div>
        </section>

        @if($automaticActions->isNotEmpty())
            <section id="automatic-actions" class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
                <div class="border-b border-orange-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                        Automatic Behavior
                    </h2>

                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        Simple one-step behavior grouped by the business activity that can start it. Assigned actions are separated from actions that are merely available.
                    </p>
                </div>

                <div class="divide-y divide-orange-100">
                    @foreach($automaticActions as $group)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-orange-300 sm:px-8">
                                <div>
                                    <span class="font-semibold text-slate-950">{{ $group['label'] }}</span>
                                    <span class="ml-2 text-sm text-slate-700">
                                        {{ $group['assigned_count'] }} currently running
                                        @if($group['action_count'] > $group['assigned_count'])
                                            · {{ $group['action_count'] - $group['assigned_count'] }} available
                                        @endif
                                    </span>
                                </div>

                                <span class="text-sm font-semibold text-slate-800 group-open:hidden">Show</span>
                                <span class="hidden text-sm font-semibold text-slate-800 group-open:inline">Hide</span>
                            </summary>

                            <div class="border-t border-orange-100 px-5 py-4 sm:px-8 {{ module_tone($group['key'], 'panel') }}">
                                <div class="space-y-4">
                                    @foreach($group['events'] as $event)
                                        @php
                                            $assignmentUrl = route('crm.flow-routes.bindings.index', $event['assignment_query']);

                                            if($event['assignment_anchor']) {
                                                $assignmentUrl .= '#'.$event['assignment_anchor'];
                                            }
                                        @endphp

                                        <article class="rounded-2xl bg-white/90 p-4 shadow-sm ring-1 ring-black/5 sm:p-5">
                                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <h3 class="font-semibold text-slate-950">
                                                        {{ $event['label'] }}
                                                    </h3>

                                                    @if($event['assigned_items']->isNotEmpty())
                                                        <div class="mt-4">
                                                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-800">
                                                                Currently runs
                                                            </p>

                                                            <ul class="mt-2 space-y-2">
                                                                @foreach($event['assigned_items'] as $action)
                                                                    @php($point = $action['presented_points'][0] ?? null)

                                                                    <li class="rounded-xl px-3 py-3 ring-1 {{ $point ? module_tone($point['module_key'], 'item') : 'bg-slate-50 ring-slate-200' }}">
                                                                        <div class="flex gap-3 text-sm leading-6 text-slate-900">
                                                                            <span class="mt-2.5 h-2 w-2 shrink-0 rounded-full bg-emerald-600" aria-hidden="true"></span>
                                                                            <span>
                                                                                {{ implode(' ', $action['summary_points']) }}

                                                                                @if($action['has_campaign_enrollment'])
                                                                                    <span class="mt-1 block text-sm text-slate-700">
                                                                                        Messages are sent only when communication permissions and delivery rules allow.
                                                                                    </span>
                                                                                @endif
                                                                            </span>
                                                                        </div>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if($event['available_items']->isNotEmpty())
                                                        <div class="mt-4">
                                                            <p class="text-xs font-bold uppercase tracking-wide text-slate-700">
                                                                Available but not assigned
                                                            </p>

                                                            <ul class="mt-2 space-y-2">
                                                                @foreach($event['available_items'] as $action)
                                                                    @php($point = $action['presented_points'][0] ?? null)

                                                                    <li class="rounded-xl px-3 py-3 opacity-80 ring-1 {{ $point ? module_tone($point['module_key'], 'item') : 'bg-slate-50 ring-slate-200' }}">
                                                                        <div class="flex gap-3 text-sm leading-6 text-slate-800">
                                                                            <span class="mt-2.5 h-2 w-2 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                                                                            <span>
                                                                                {{ implode(' ', $action['summary_points']) }}

                                                                                @if($action['has_campaign_enrollment'])
                                                                                    <span class="mt-1 block text-sm text-slate-700">
                                                                                        If assigned, messages are still sent only when communication permissions and delivery rules allow.
                                                                                    </span>
                                                                                @endif
                                                                            </span>
                                                                        </div>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                </div>

                                                <a
                                                    href="{{ $assignmentUrl }}"
                                                    class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:border-slate-400 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                                                >
                                                    Review Assignment
                                                </a>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.crm>
