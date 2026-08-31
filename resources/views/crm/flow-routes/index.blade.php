<x-layouts.crm
    title="Routes"
    heading="Routes"
    subheading="Review and change the paths your system can run automatically."
    module="flow_routes"
>
    <div
        class="space-y-6"
        x-data="{
            openRouteEditor: @js($openRouteEditorId),
            openCreateRoute: @js((bool) $openCreateRoute),
            createTriggerKey: @js($createRouteTriggerKey),
            createTriggerValues: @js($createRouteTriggerValues),
            openRoute(id) {
                this.openRouteEditor = Number(id);
                const url = new URL(window.location.href);
                url.searchParams.set('edit_route', String(id));
                window.history.replaceState({}, '', url);
            },
            closeRoute() {
                this.openRouteEditor = null;
                const url = new URL(window.location.href);
                url.searchParams.delete('edit_route');
                window.history.replaceState({}, '', url);
            },
            openCreate() {
                this.openCreateRoute = true;
                const url = new URL(window.location.href);
                url.searchParams.set('create', '1');
                window.history.replaceState({}, '', url);
            },
            closeCreate() {
                this.openCreateRoute = false;
                const url = new URL(window.location.href);
                url.searchParams.delete('create');
                url.searchParams.delete('status');
                window.history.replaceState({}, '', url);
            },
        }"
        x-effect="document.body.classList.toggle('overflow-hidden', openRouteEditor !== null || openCreateRoute)"
    >
        @include('crm.flow-routes.partials.navigation')

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
            <div class="p-5 sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">
                    Manage Routes
                </p>

                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    Understand and change what happens automatically
                </h2>

                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">
                    Routes connect repetitive actions, waits, and follow-up work into a clear path. Review a Route below to understand what it does.
                </p>

                <div class="mt-5">
                    <button
                        type="button"
                        x-on:click="openCreate()"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:w-auto"
                        data-flow-route-create
                    >
                        Create Route
                    </button>
                </div>

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
            <div class="border-b border-orange-100 p-5 sm:p-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                            Routes
                        </h2>

                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-700">
                            Multi-step paths that take repetitive coordination work off your plate.
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
                            ...$route['entry_condition_summaries'],
                            ...$route['summary_points'],
                        ]));
                    @endphp

                    <article
                        class="p-5 sm:p-8"
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

                                @foreach(($route['entry_condition_summaries'] ?? []) as $entryConditionSummary)
                                    <p class="mt-1 text-sm leading-6 text-slate-700">
                                        {{ $entryConditionSummary }}
                                    </p>
                                @endforeach

                                @if(count($route['presented_points']) > 0)
                                    <details class="group mt-5">
                                        <summary class="flex w-full cursor-pointer list-none flex-wrap items-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2.5 text-sm font-semibold text-orange-950 transition hover:bg-orange-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:inline-flex sm:w-auto sm:py-2">
                                            <span>{{ $route['point_count'] }} {{ \Illuminate\Support\Str::plural('Point', $route['point_count']) }}</span>
                                            <span class="text-orange-700 group-open:hidden">· Show route flow</span>
                                            <span class="hidden text-orange-700 group-open:inline">· Hide route flow</span>
                                        </summary>

                                        <ol class="mt-4 max-w-3xl space-y-2" aria-label="Route flow">
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

                                                        @if(($point['decision_paths'] ?? []) !== [])
                                                            <span data-flow-route-decision-paths class="mt-2 grid gap-1.5">
                                                                @foreach($point['decision_paths'] as $path)
                                                                    <span class="rounded-lg bg-white/85 px-2.5 py-2 text-xs leading-5 ring-1 ring-black/10">
                                                                        <span class="block font-semibold text-slate-800">{{ $path['condition'] }}</span>
                                                                        <span class="block text-slate-600">
                                                                            @if(($path['destination_key'] ?? null) === null)
                                                                                End this Route
                                                                            @else
                                                                                Continue at: {{ $path['destination'] }}
                                                                            @endif
                                                                        </span>
                                                                    </span>
                                                                @endforeach
                                                            </span>
                                                        @endif
                                                        </span>
                                                    </div>

                                                    @unless($loop->last)
                                                        <span class="shrink-0 self-center text-lg font-bold text-orange-500" aria-hidden="true">↓</span>
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

                            <div class="grid w-full shrink-0 gap-2 sm:grid-cols-2 xl:flex xl:w-auto xl:flex-wrap xl:justify-end">
                                <button
                                    type="button"
                                    @click="openRoute({{ $route['id'] }})"
                                    class="inline-flex w-full items-center justify-center rounded-xl border border-orange-300 bg-white px-4 py-2.5 text-sm font-semibold text-orange-900 shadow-sm transition hover:bg-orange-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 xl:w-auto"
                                >
                                    Edit Route
                                </button>

                                <a
                                    href="{{ $assignmentUrl }}"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 xl:w-auto"
                                >
                                    {{ $route['assignment_count'] > 0 ? 'Review Assignment' : 'Assign Route' }}
                                </a>
                            </div>
                        </div>

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
                <div class="border-b border-orange-100 p-5 sm:p-8">
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
                            <summary class="flex cursor-pointer list-none flex-col items-start gap-2 p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-orange-300 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-8">
                                <div>
                                    <span class="font-semibold text-slate-950">{{ $group['label'] }}</span>
                                    <span class="mt-1 block text-sm text-slate-700 sm:ml-2 sm:mt-0 sm:inline">
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
                                                                    @php
                                                                        $point = $action['presented_points'][0] ?? null;
                                                                    @endphp

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
                                                                    @php
                                                                        $point = $action['presented_points'][0] ?? null;
                                                                    @endphp

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
                                                    class="inline-flex w-full shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:border-slate-400 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:w-auto sm:py-2"
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


        <template x-teleport="body">
            <div
                x-cloak
                x-show="openCreateRoute"
                x-transition.opacity
                x-on:keydown.escape.window="closeCreate()"
                x-on:click.self="closeCreate()"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/55 p-4"
                role="presentation"
                data-flow-route-create-modal
            >
                <section
                    x-show="openCreateRoute"
                    x-transition
                    class="max-h-[90dvh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl ring-1 ring-black/10"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="create-route-heading"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-700">Flow Routes</p>
                            <h2 id="create-route-heading" class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Create Route</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Choose the real-world activity that should start this Route. You will build the Route before assigning it.</p>
                        </div>
                        <button type="button" x-on:click="closeCreate()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-950" aria-label="Close Create Route">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" /></svg>
                        </button>
                    </header>

                    <form method="POST" action="{{ route('crm.flow-routes.store') }}" class="space-y-5 px-5 py-5 sm:px-6">
                        @csrf
                        <input type="hidden" name="_flow_route_create" value="1">

                        <div>
                            <label for="create-route-name" class="text-sm font-semibold text-slate-900">Route name <span class="text-red-700" aria-hidden="true">*</span></label>
                            <input id="create-route-name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" placeholder="Past Client Follow-Up" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200">
                            @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="create-route-trigger" class="text-sm font-semibold text-slate-900">Runs when <span class="text-red-700" aria-hidden="true">*</span></label>
                            <select id="create-route-trigger" name="trigger_authoring_key" required x-model="createTriggerKey" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200" data-flow-route-create-trigger>
                                <option value="">Choose what starts this Route</option>
                                @foreach(collect($createRouteTriggers)->groupBy('module_label') as $moduleLabel => $moduleTriggers)
                                    <optgroup label="{{ $moduleLabel }}">
                                        @foreach($moduleTriggers as $trigger)
                                            <option value="{{ $trigger['key'] }}" @selected((string) old('trigger_authoring_key', $createRouteTriggerKey) === (string) $trigger['key'])>{{ $trigger['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Only activities available in this CRM are shown.</p>
                            @error('trigger_authoring_key')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>

                        @foreach($createRouteTriggers as $trigger)
                            <section
                                x-show="createTriggerKey === @js($trigger['key'])"
                                class="space-y-4 rounded-2xl border px-4 py-4 {{ module_tone($trigger['module_key'], 'panel') }}"
                                data-flow-route-trigger-fields="{{ $trigger['key'] }}"
                            >
                                <div>
                                    <p class="text-sm font-semibold text-slate-950">{{ $trigger['name'] }}</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $trigger['description'] }}</p>
                                </div>

                                @foreach($trigger['fields'] as $field)
                                    @php
                                        $fieldName = (string) $field['name'];
                                        $fieldId = 'create-route-'.str_replace('_', '-', $fieldName);
                                        $fieldValue = old($fieldName, $createRouteTriggerValues[$fieldName] ?? '');
                                        $fieldRequired = (bool) ($field['required'] ?? false);
                                    @endphp
                                    <div>
                                        <label for="{{ $fieldId }}" class="text-sm font-semibold text-slate-900">
                                            {{ $field['label'] }}
                                            @if($fieldRequired)<span class="text-red-700" aria-hidden="true">*</span>@endif
                                        </label>

                                        <select
                                            id="{{ $fieldId }}"
                                            name="{{ $fieldName }}"
                                            x-model="createTriggerValues.{{ $fieldName }}"
                                            x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                                            @if($fieldRequired) x-bind:required="createTriggerKey === @js($trigger['key'])" @endif
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                        >
                                            <option value="">{{ $field['placeholder'] ?? 'Choose an option' }}</option>
                                            @foreach(($field['options'] ?? []) as $option)
                                                <option value="{{ $option['value'] }}" @selected((string) $fieldValue === (string) $option['value'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>

                                        @if(filled($field['help'] ?? null))
                                            <p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>
                                        @endif
                                        @error($fieldName)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </section>
                        @endforeach

                        <div>
                            <label for="create-route-description" class="text-sm font-semibold text-slate-900">Description <span class="font-normal text-slate-500">Optional</span></label>
                            <textarea id="create-route-description" name="description" rows="3" maxlength="2000" placeholder="What this Route is meant to accomplish." class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200">{{ old('description') }}</textarea>
                            @error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                            <span class="font-semibold">Safe by default:</span> creating this Route does not turn it on for that activity. Add and review its Points first, then choose it in Assignments when it is ready to run.
                        </div>

                        <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                            <button type="button" x-on:click="closeCreate()" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50 sm:w-auto">Cancel</button>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 sm:w-auto">Create and build Route</button>
                        </div>
                    </form>
                </section>
            </div>
        </template>

        @foreach($routeEditors as $editor)
            @include('crm.flow-routes.partials.editor-modal', [
                'editor' => $editor,
                'editorOptions' => $editorOptions,
            ])
        @endforeach
    </div>
</x-layouts.crm>