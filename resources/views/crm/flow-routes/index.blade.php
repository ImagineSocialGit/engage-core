<x-layouts.crm
    title="Routes"
    heading="Routes"
    subheading="Review and change what your system does automatically."
    module="flow_routes"
>
    @php
        $automationCount = (int) $routeSummary['routes'] + (int) $routeSummary['automatic_behaviors'];
    @endphp

    <div
        class="space-y-6"
        x-data="{
            openRouteEditor: @js($openRouteEditorId),
            openCreateRoute: @js((bool) $openCreateRoute),
            createKind: @js($openCreateKind),
            createTriggerKey: @js($createRouteTriggerKey),
            createTriggerValues: @js($createRouteTriggerValues),
            search: '',
            state: 'all',
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
            openCreate(kind = 'route') {
                this.createKind = kind;
                this.openCreateRoute = true;
                const url = new URL(window.location.href);
                url.searchParams.set('create', '1');
                url.searchParams.set('create_kind', kind);
                window.history.replaceState({}, '', url);
            },
            closeCreate() {
                this.openCreateRoute = false;
                const url = new URL(window.location.href);
                url.searchParams.delete('create');
                url.searchParams.delete('create_kind');
                url.searchParams.delete('status');
                window.history.replaceState({}, '', url);
            },
            matches(element) {
                const query = this.search.trim().toLowerCase();
                const haystack = element.dataset.search || '';
                const matchesSearch = query === '' || haystack.includes(query);
                const matchesState = this.state === 'all' || element.dataset.state === this.state;

                return matchesSearch && matchesState;
            },
        }"
        x-effect="document.body.classList.toggle('overflow-hidden', openRouteEditor !== null || openCreateRoute)"
    >
        @if(session('status') && ! request()->integer('edit_route'))
            <x-ui.feedback.alert type="success">
                {{ session('status') }}
            </x-ui.feedback.alert>
        @endif

        @if($errors->any() && ! request()->integer('edit_route'))
            <x-ui.feedback.alert type="error">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-3xl border border-orange-200 bg-white/90 p-5 shadow-sm sm:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">Automations</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">When something happens, decide what follows</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        Use an Automatic behavior for one action. Use a Route when the work needs multiple steps, timing, or decisions.
                    </p>
                </div>

                <button
                    type="button"
                    x-on:click="openCreate('route')"
                    class="inline-flex w-full shrink-0 items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 lg:w-auto"
                    data-flow-route-create
                >
                    Create automation
                </button>
            </div>

            <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold text-slate-700" aria-label="Automation summary">
                <span class="rounded-full bg-orange-50 px-3 py-1.5 ring-1 ring-orange-200">
                    {{ $routeSummary['routes'] }} {{ \Illuminate\Support\Str::plural('Route', $routeSummary['routes']) }}
                </span>
                <span class="rounded-full bg-sky-50 px-3 py-1.5 ring-1 ring-sky-200">
                    {{ $routeSummary['automatic_behaviors'] }} Automatic {{ \Illuminate\Support\Str::plural('behavior', $routeSummary['automatic_behaviors']) }}
                </span>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5 ring-1 ring-emerald-200">
                    {{ $routeSummary['enabled'] }} on
                </span>
            </div>

            @if($automationCount >= 5)
                <div class="mt-5 grid gap-3 border-t border-orange-100 pt-5 sm:grid-cols-[minmax(16rem,1fr)_10rem]">
                    <label class="text-sm font-semibold text-slate-900">
                        Search automations
                        <input
                            type="search"
                            x-model.debounce.200ms="search"
                            placeholder="Name, trigger, or step"
                            class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                        >
                    </label>

                    <label class="text-sm font-semibold text-slate-900">
                        State
                        <select x-model="state" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <option value="all">All</option>
                            <option value="on">On</option>
                            <option value="off">Off</option>
                        </select>
                    </label>
                </div>
            @endif

            @if($routeSummary['conflicts'] > 0)
                <div class="mt-5 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm leading-6 text-red-950">
                    <span class="font-semibold">Conflicting automations are running.</span>
                    Review the marked items below and turn off, edit, or delete one of each conflicting pair.
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
            <div class="border-b border-orange-100 p-5 sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">Routes</h2>
                <p class="mt-1 text-sm leading-6 text-slate-700">Multi-step paths with actions, timing, and decisions.</p>
            </div>

            <div class="divide-y divide-orange-100">
                @forelse($routes as $route)
                    @php
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
                        data-state="{{ $route['is_enabled'] ? 'on' : 'off' }}"
                        x-show="matches($el)"
                    >
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-xl font-semibold tracking-tight text-slate-950">{{ $route['name'] }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $route['is_enabled'] ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-slate-100 text-slate-800 ring-slate-300' }}">
                                        {{ $route['is_enabled'] ? 'On' : 'Off' }}
                                    </span>
                                </div>

                                @if(filled($route['description']))
                                    <p class="mt-2 text-sm leading-6 text-slate-700">{{ $route['description'] }}</p>
                                @endif

                                <p class="mt-3 text-sm font-semibold text-slate-900">{{ $route['trigger_summary'] }}</p>

                                @foreach($route['entry_condition_summaries'] as $summary)
                                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ $summary }}</p>
                                @endforeach

                                @if($route['conflict_names'] !== [])
                                    <p class="mt-4 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-950">
                                        Conflicts with {{ implode(', ', $route['conflict_names']) }} because both change the same result from the same event.
                                    </p>
                                @endif

                                @if($route['presented_points'] !== [])
                                    <details class="group mt-4 max-w-3xl" data-flow-route-step-list>
                                        <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-950 marker:content-none hover:bg-orange-100">
                                            <span>{{ $route['point_count'] }} {{ \Illuminate\Support\Str::plural('step', $route['point_count']) }}</span>
                                            <span class="font-normal group-open:hidden">Show steps</span>
                                            <span class="hidden font-normal group-open:inline">Hide steps</span>
                                        </summary>

                                        <ol class="mt-3 space-y-2" aria-label="Route steps">
                                            @foreach($route['presented_points'] as $index => $point)
                                                <li class="flex min-w-0 items-start gap-2">
                                                    <div class="flex min-w-0 flex-1 items-start gap-3 rounded-xl px-3 py-3 text-sm ring-1 {{ module_tone($point['module_key'], 'item') }}" data-module="{{ $point['module_key'] }}">
                                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold ring-1">{{ $index + 1 }}</span>
                                                        <span>{{ $point['summary'] }}</span>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                    </details>
                                @else
                                    <p class="mt-4 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                        This Route still needs its first step.
                                    </p>
                                @endif
                            </div>

                            <div class="grid w-full shrink-0 gap-2 sm:grid-cols-3 xl:flex xl:w-auto xl:flex-wrap xl:justify-end">
                                <button type="button" @click="openRoute({{ $route['id'] }})" class="rounded-xl border border-orange-300 bg-white px-4 py-2.5 text-sm font-semibold text-orange-900 shadow-sm hover:bg-orange-50">
                                    Edit Route
                                </button>

                                <form method="POST" action="{{ route('crm.flow-routes.enabled.update', $route['id']) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $route['is_enabled'] ? 0 : 1 }}">
                                    <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                        Turn {{ $route['is_enabled'] ? 'off' : 'on' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('crm.flow-routes.destroy', $route['id']) }}" onsubmit="return confirm('Delete this Route? Historical activity will be preserved.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center">
                        <h3 class="font-semibold text-slate-950">No Routes yet</h3>
                        <p class="mt-2 text-sm text-slate-700">Create one when an event should lead to several steps.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section id="automatic-behaviors" class="rounded-3xl border border-sky-200 bg-white/90 shadow-sm">
            <div class="border-b border-sky-100 p-5 sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">Automatic behaviors</h2>
                <p class="mt-1 text-sm leading-6 text-slate-700">Simple rules that perform one action and finish.</p>
            </div>

            <div class="divide-y divide-sky-100">
                @forelse($automaticBehaviors as $behavior)
                    @php
                        $point = $behavior['presented_points'][0] ?? null;
                        $triggerText = (string) \Illuminate\Support\Str::of($behavior['trigger_summary'])
                            ->rtrim('.')
                            ->replaceStart('When ', '');
                        $actionText = $point ? rtrim((string) $point['summary'], '.') : null;
                        $searchText = \Illuminate\Support\Str::lower(implode(' ', array_filter([
                            $behavior['name'],
                            $behavior['description'],
                            $behavior['group_label'],
                            $behavior['trigger_summary'],
                            ...$behavior['entry_condition_summaries'],
                            $actionText,
                        ])));
                    @endphp

                    <article
                        class="p-5 sm:p-8"
                        data-search="{{ $searchText }}"
                        data-state="{{ $behavior['is_enabled'] ? 'on' : 'off' }}"
                        x-show="matches($el)"
                    >
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-semibold text-slate-950">{{ $behavior['name'] }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $behavior['is_enabled'] ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-slate-100 text-slate-800 ring-slate-300' }}">
                                        {{ $behavior['is_enabled'] ? 'On' : 'Off' }}
                                    </span>
                                    <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300">
                                        {{ $behavior['group_label'] }}
                                    </span>
                                </div>

                                <dl class="mt-4 grid max-w-3xl grid-cols-[4rem_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm leading-6" data-automatic-behavior-sentence>
                                    <dt class="font-semibold text-slate-500">When</dt>
                                    <dd class="font-medium text-slate-950">{{ $triggerText }}.</dd>

                                    @if($behavior['entry_condition_summaries'] !== [])
                                        <dt class="font-semibold text-slate-500">Only if</dt>
                                        <dd class="text-slate-700">{{ implode(' ', $behavior['entry_condition_summaries']) }}</dd>
                                    @endif

                                    <dt class="font-semibold text-slate-500">Then</dt>
                                    <dd class="text-slate-950">
                                        {{ $actionText ? $actionText.'.' : 'Choose the action this behavior should perform.' }}
                                    </dd>
                                </dl>

                                @if($behavior['conflict_names'] !== [])
                                    <p class="mt-3 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-950">
                                        Conflicts with {{ implode(', ', $behavior['conflict_names']) }}. Turn off, edit, or delete one.
                                    </p>
                                @endif
                            </div>

                            <div class="grid w-full shrink-0 gap-2 sm:grid-cols-3 xl:flex xl:w-auto">
                                <button type="button" @click="openRoute({{ $behavior['id'] }})" class="rounded-xl border border-sky-300 bg-white px-4 py-2.5 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-50">
                                    Edit
                                </button>

                                <form method="POST" action="{{ route('crm.flow-routes.enabled.update', $behavior['id']) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $behavior['is_enabled'] ? 0 : 1 }}">
                                    <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                        Turn {{ $behavior['is_enabled'] ? 'off' : 'on' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('crm.flow-routes.destroy', $behavior['id']) }}" onsubmit="return confirm('Delete this Automatic behavior? Historical activity will be preserved.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center">
                        <h3 class="font-semibold text-slate-950">No Automatic behaviors yet</h3>
                        <p class="mt-2 text-sm text-slate-700">Create one for a simple “When this happens, do this” rule.</p>
                    </div>
                @endforelse
            </div>
        </section>

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
                    aria-labelledby="create-automation-heading"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-700">Automations</p>
                            <h2 id="create-automation-heading" class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Create automation</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Choose what starts it and how much should happen.</p>
                        </div>

                        <button type="button" x-on:click="closeCreate()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-600" aria-label="Close">
                            ×
                        </button>
                    </header>

                    <form method="POST" action="{{ route('crm.flow-routes.store') }}" class="space-y-5 px-5 py-5 sm:px-6">
                        @csrf
                        <input type="hidden" name="_flow_route_create" value="1">
                        <input type="hidden" name="authoring_kind" x-bind:value="createKind">

                        <fieldset>
                            <legend class="text-sm font-semibold text-slate-900">How much should happen? <span class="text-red-700">*</span></legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <label class="flex cursor-pointer gap-3 rounded-2xl border border-sky-200 bg-sky-50/60 p-4 has-checked:border-sky-500 has-checked:ring-2 has-checked:ring-sky-200">
                                    <input type="radio" name="create_kind_choice" value="automatic_behavior" x-model="createKind" class="mt-1 border-slate-300 text-sky-700 focus:ring-sky-500">
                                    <span>
                                        <span class="block font-semibold text-slate-950">One action</span>
                                        <span class="mt-1 block text-xs leading-5 text-slate-600">Create an Automatic behavior that acts once and finishes.</span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer gap-3 rounded-2xl border border-orange-200 bg-orange-50/60 p-4 has-checked:border-orange-500 has-checked:ring-2 has-checked:ring-orange-200">
                                    <input type="radio" name="create_kind_choice" value="route" x-model="createKind" class="mt-1 border-slate-300 text-orange-700 focus:ring-orange-500">
                                    <span>
                                        <span class="block font-semibold text-slate-950">Several steps</span>
                                        <span class="mt-1 block text-xs leading-5 text-slate-600">Create a Route with actions, timing, or decisions.</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <div>
                            <label for="create-route-trigger" class="text-sm font-semibold text-slate-900">Runs when <span class="text-red-700">*</span></label>
                            <select id="create-route-trigger" name="trigger_authoring_key" required x-model="createTriggerKey" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" data-flow-route-create-trigger>
                                <option value="">Choose what starts this automation</option>
                                @foreach(collect($createRouteTriggers)->groupBy('module_label') as $moduleLabel => $moduleTriggers)
                                    <optgroup label="{{ $moduleLabel }}">
                                        @foreach($moduleTriggers as $trigger)
                                            <option value="{{ $trigger['key'] }}" @selected((string) old('trigger_authoring_key', $createRouteTriggerKey) === (string) $trigger['key'])>
                                                {{ $trigger['name'] }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('trigger_authoring_key')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
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
                                            @if($fieldRequired)
                                                <span class="text-red-700">*</span>
                                            @endif
                                        </label>
                                        <select
                                            id="{{ $fieldId }}"
                                            name="{{ $fieldName }}"
                                            x-model="createTriggerValues.{{ $fieldName }}"
                                            x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                                            @if($fieldRequired) x-bind:required="createTriggerKey === @js($trigger['key'])" @endif
                                            class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                        >
                                            <option value="">{{ $field['placeholder'] ?? 'Choose an option' }}</option>
                                            @foreach(($field['options'] ?? []) as $option)
                                                <option value="{{ $option['value'] }}" @selected((string) $fieldValue === (string) $option['value'])>
                                                    {{ $option['label'] }}
                                                </option>
                                            @endforeach
                                        </select>

                                        @if(filled($field['help'] ?? null))
                                            <p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>
                                        @endif

                                        @error($fieldName)
                                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endforeach
                            </section>
                        @endforeach

                        <div>
                            <label for="create-route-name" class="text-sm font-semibold text-slate-900">Name <span class="text-red-700">*</span></label>
                            <input
                                id="create-route-name"
                                name="name"
                                type="text"
                                value="{{ old('name') }}"
                                required
                                maxlength="255"
                                x-bind:placeholder="createKind === 'automatic_behavior' ? 'Move scheduled appointments to Engaged' : 'Appointment follow-up'"
                                class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                            >
                            @error('name')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="create-route-description" class="text-sm font-semibold text-slate-900">Description <span class="font-normal text-slate-500">Optional</span></label>
                            <textarea id="create-route-description" name="description" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">{{ old('description') }}</textarea>
                        </div>

                        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                            <span class="font-semibold">Safe by default:</span>
                            this automation stays off until you finish it and turn it on.
                        </div>

                        <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                            <button type="button" x-on:click="closeCreate()" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">Cancel</button>
                            <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white" x-text="createKind === 'automatic_behavior' ? 'Create and choose action' : 'Create and add steps'"></button>
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