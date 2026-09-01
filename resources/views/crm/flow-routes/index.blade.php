<x-layouts.crm
    title="Routes"
    heading="Routes"
    subheading="Review and change what your system does automatically."
    module="flow_routes"
>
    <div
        class="space-y-6"
        x-data="{
            openRouteEditor: @js($openRouteEditorId),
            openCreateRoute: @js((bool) $openCreateRoute),
            createKind: @js($openCreateKind),
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
            openCreate(kind) {
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
        }"
        x-effect="document.body.classList.toggle('overflow-hidden', openRouteEditor !== null || openCreateRoute)"
    >
        @if(session('status') && ! request()->integer('edit_route'))
            <x-ui.feedback.alert type="success">{{ session('status') }}</x-ui.feedback.alert>
        @endif

        @if($errors->any() && ! request()->integer('edit_route'))
            <x-ui.feedback.alert type="error">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-3xl border border-orange-200 bg-white/90 p-5 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">Automations</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">Choose the right shape for the work</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-orange-200 bg-orange-50/60 p-4">
                    <h3 class="font-semibold text-slate-950">Route</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-700">When something happens, complete a sequence of actions, decisions, or waits.</p>
                    <button type="button" x-on:click="openCreate('route')" class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 sm:w-auto" data-flow-route-create>
                        Create Route
                    </button>
                </div>
                <div class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4">
                    <h3 class="font-semibold text-slate-950">Automatic behavior</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-700">When something happens, complete one action and finish.</p>
                    <button type="button" x-on:click="openCreate('automatic_behavior')" class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-sky-300 bg-white px-4 py-2.5 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-50 sm:w-auto" data-automatic-behavior-create>
                        Create Automatic behavior
                    </button>
                </div>
            </div>

            @if($routeSummary['conflicts'] > 0)
                <div class="mt-5 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm leading-6 text-red-950">
                    <span class="font-semibold">Conflicting automations are running.</span>
                    Review the marked items below and turn off, edit, or delete one of each conflicting pair.
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm" x-data="{ search: '', state: 'all', matches(element) { const query = this.search.trim().toLowerCase(); return (query === '' || element.dataset.search.includes(query)) && (this.state === 'all' || element.dataset.state === this.state); } }">
            <div class="border-b border-orange-100 p-5 sm:p-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">Routes</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-700">Multi-step paths shown as “When this happens, do these things.”</p>
                    </div>
                    @if($routes->count() >= 5)
                        <div class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_10rem]">
                            <label class="text-sm font-semibold text-slate-900">Search
                                <input type="search" x-model.debounce.200ms="search" placeholder="Name, trigger, or action" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            </label>
                            <label class="text-sm font-semibold text-slate-900">State
                                <select x-model="state" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option value="all">All</option><option value="on">On</option><option value="off">Off</option>
                                </select>
                            </label>
                        </div>
                    @endif
                </div>
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
                    <article class="p-5 sm:p-8" data-search="{{ $searchText }}" data-state="{{ $route['is_enabled'] ? 'on' : 'off' }}" x-show="matches($el)">
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-xl font-semibold tracking-tight text-slate-950">{{ $route['name'] }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $route['is_enabled'] ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-slate-100 text-slate-800 ring-slate-300' }}">{{ $route['is_enabled'] ? 'On' : 'Off' }}</span>
                                </div>
                                <p class="mt-3 text-sm font-semibold text-slate-900">{{ $route['trigger_summary'] }}</p>
                                @foreach($route['entry_condition_summaries'] as $summary)<p class="mt-1 text-sm leading-6 text-slate-700">{{ $summary }}</p>@endforeach

                                @if($route['conflict_names'] !== [])
                                    <p class="mt-4 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-950">
                                        Conflicts with {{ implode(', ', $route['conflict_names']) }} because both change the same result from the same event.
                                    </p>
                                @endif

                                @if($route['presented_points'] !== [])
                                    <ol class="mt-5 max-w-3xl space-y-2" aria-label="Route flow">
                                        @foreach($route['presented_points'] as $index => $point)
                                            <li class="flex min-w-0 items-center gap-2">
                                                <div class="flex min-w-0 flex-1 items-start gap-3 rounded-xl px-3 py-3 text-sm ring-1 {{ module_tone($point['module_key'], 'item') }}" data-module="{{ $point['module_key'] }}">
                                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold ring-1">{{ $index + 1 }}</span>
                                                    <span>{{ $point['summary'] }}</span>
                                                </div>
                                                @unless($loop->last)<span class="font-bold text-orange-500" aria-hidden="true">↓</span>@endunless
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="mt-4 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">This Route still needs its first action.</p>
                                @endif

                                @if($route['point_count'] === 1)
                                    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-3 py-3 text-sm leading-6 text-sky-950">
                                        <span class="font-semibold">Only one action?</span> If this is meant to finish after that action, make it an Automatic behavior.
                                        <form method="POST" action="{{ route('crm.flow-routes.kind.update', $route['id']) }}" class="mt-2">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="authoring_kind" value="automatic_behavior">
                                            <button type="submit" class="rounded-lg border border-sky-300 bg-white px-3 py-2 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-100">Make Automatic behavior</button>
                                        </form>
                                    </div>
                                @endif
                            </div>

                            <div class="grid w-full shrink-0 gap-2 sm:grid-cols-3 xl:flex xl:w-auto xl:flex-wrap xl:justify-end">
                                <button type="button" @click="openRoute({{ $route['id'] }})" class="rounded-xl border border-orange-300 bg-white px-4 py-2.5 text-sm font-semibold text-orange-900 shadow-sm hover:bg-orange-50">Edit Route</button>
                                <form method="POST" action="{{ route('crm.flow-routes.enabled.update', $route['id']) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $route['is_enabled'] ? 0 : 1 }}">
                                    <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Turn {{ $route['is_enabled'] ? 'off' : 'on' }}</button>
                                </form>
                                <form method="POST" action="{{ route('crm.flow-routes.destroy', $route['id']) }}" onsubmit="return confirm('Delete this Route? Historical activity will be preserved.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">Delete</button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center"><h3 class="font-semibold text-slate-950">No Routes yet</h3><p class="mt-2 text-sm text-slate-700">Create one when an event should lead to more than one action.</p></div>
                @endforelse
            </div>
        </section>

        <section id="automatic-behaviors" class="rounded-3xl border border-sky-200 bg-white/90 shadow-sm">
            <div class="border-b border-sky-100 p-5 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div><h2 class="text-xl font-semibold tracking-tight text-slate-950">Automatic behaviors</h2><p class="mt-1 text-sm leading-6 text-slate-700">Simple rules: when one thing happens, one action follows.</p></div>
                    <button type="button" x-on:click="openCreate('automatic_behavior')" class="rounded-xl border border-sky-300 bg-white px-4 py-2.5 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-50">Create Automatic behavior</button>
                </div>
            </div>
            <div class="divide-y divide-sky-100">
                @forelse($automaticBehaviors as $behavior)
                    @php
                        $point = $behavior['presented_points'][0] ?? null;
                    @endphp
                    <article class="p-5 sm:p-8">
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2"><h3 class="text-lg font-semibold text-slate-950">{{ $behavior['name'] }}</h3><span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $behavior['is_enabled'] ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-slate-100 text-slate-800 ring-slate-300' }}">{{ $behavior['is_enabled'] ? 'On' : 'Off' }}</span></div>
                                <p class="mt-3 text-sm leading-6 text-slate-800"><span class="font-semibold">{{ $behavior['trigger_summary'] }}</span> {{ $point ? rtrim($point['summary'], '.').'.' : 'No action has been chosen yet.' }}</p>
                                @foreach($behavior['entry_condition_summaries'] as $summary)<p class="mt-1 text-sm leading-6 text-slate-700">{{ $summary }}</p>@endforeach
                                @if($behavior['conflict_names'] !== [])<p class="mt-3 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-950">Conflicts with {{ implode(', ', $behavior['conflict_names']) }}. Turn off, edit, or delete one.</p>@endif
                            </div>
                            <div class="grid w-full shrink-0 gap-2 sm:grid-cols-3 xl:flex xl:w-auto">
                                <button type="button" @click="openRoute({{ $behavior['id'] }})" class="rounded-xl border border-sky-300 bg-white px-4 py-2.5 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-50">Edit</button>
                                <form method="POST" action="{{ route('crm.flow-routes.enabled.update', $behavior['id']) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $behavior['is_enabled'] ? 0 : 1 }}"><button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Turn {{ $behavior['is_enabled'] ? 'off' : 'on' }}</button></form>
                                <form method="POST" action="{{ route('crm.flow-routes.destroy', $behavior['id']) }}" onsubmit="return confirm('Delete this Automatic behavior? Historical activity will be preserved.');">@csrf @method('DELETE')<button type="submit" class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">Delete</button></form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center"><h3 class="font-semibold text-slate-950">No Automatic behaviors yet</h3><p class="mt-2 text-sm text-slate-700">Create one for a simple “When X, do Y” rule.</p></div>
                @endforelse
            </div>
        </section>

        <template x-teleport="body">
            <div x-cloak x-show="openCreateRoute" x-transition.opacity x-on:keydown.escape.window="closeCreate()" x-on:click.self="closeCreate()" class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" data-flow-route-create-modal>
                <section x-show="openCreateRoute" x-transition class="max-h-[90dvh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl ring-1 ring-black/10" role="dialog" aria-modal="true" aria-labelledby="create-automation-heading">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                        <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-700">Automations</p><h2 id="create-automation-heading" class="mt-1 text-xl font-semibold tracking-tight text-slate-950" x-text="createKind === 'automatic_behavior' ? 'Create Automatic behavior' : 'Create Route'"></h2><p class="mt-2 text-sm leading-6 text-slate-600" x-text="createKind === 'automatic_behavior' ? 'Choose what starts this one-action rule.' : 'Choose what starts this multi-step path.'"></p></div>
                        <button type="button" x-on:click="closeCreate()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-600" aria-label="Close">×</button>
                    </header>
                    <form method="POST" action="{{ route('crm.flow-routes.store') }}" class="space-y-5 px-5 py-5 sm:px-6">
                        @csrf
                        <input type="hidden" name="_flow_route_create" value="1"><input type="hidden" name="authoring_kind" x-bind:value="createKind">
                        <div><label for="create-route-name" class="text-sm font-semibold text-slate-900">Name <span class="text-red-700">*</span></label><input id="create-route-name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" placeholder="Appointment follow-up" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">@error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                        <div><label for="create-route-trigger" class="text-sm font-semibold text-slate-900">Runs when <span class="text-red-700">*</span></label><select id="create-route-trigger" name="trigger_authoring_key" required x-model="createTriggerKey" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" data-flow-route-create-trigger><option value="">Choose what starts this automation</option>@foreach(collect($createRouteTriggers)->groupBy('module_label') as $moduleLabel => $moduleTriggers)<optgroup label="{{ $moduleLabel }}">@foreach($moduleTriggers as $trigger)<option value="{{ $trigger['key'] }}" @selected((string) old('trigger_authoring_key', $createRouteTriggerKey) === (string) $trigger['key'])>{{ $trigger['name'] }}</option>@endforeach</optgroup>@endforeach</select>@error('trigger_authoring_key')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                        @foreach($createRouteTriggers as $trigger)
                            <section x-show="createTriggerKey === @js($trigger['key'])" class="space-y-4 rounded-2xl border px-4 py-4 {{ module_tone($trigger['module_key'], 'panel') }}" data-flow-route-trigger-fields="{{ $trigger['key'] }}">
                                <div><p class="text-sm font-semibold text-slate-950">{{ $trigger['name'] }}</p><p class="mt-1 text-xs leading-5 text-slate-600">{{ $trigger['description'] }}</p></div>
                                @foreach($trigger['fields'] as $field)
                                    @php
                                        $fieldName = (string) $field['name'];
                                        $fieldId = 'create-route-'.str_replace('_', '-', $fieldName);
                                        $fieldValue = old($fieldName, $createRouteTriggerValues[$fieldName] ?? '');
                                        $fieldRequired = (bool) ($field['required'] ?? false);
                                    @endphp
                                    <div><label for="{{ $fieldId }}" class="text-sm font-semibold text-slate-900">{{ $field['label'] }} @if($fieldRequired)<span class="text-red-700">*</span>@endif</label><select id="{{ $fieldId }}" name="{{ $fieldName }}" x-model="createTriggerValues.{{ $fieldName }}" x-bind:disabled="createTriggerKey !== @js($trigger['key'])" @if($fieldRequired) x-bind:required="createTriggerKey === @js($trigger['key'])" @endif class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"><option value="">{{ $field['placeholder'] ?? 'Choose an option' }}</option>@foreach(($field['options'] ?? []) as $option)<option value="{{ $option['value'] }}" @selected((string) $fieldValue === (string) $option['value'])>{{ $option['label'] }}</option>@endforeach</select>@if(filled($field['help'] ?? null))<p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>@endif @error($fieldName)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                                @endforeach
                            </section>
                        @endforeach
                        <div><label for="create-route-description" class="text-sm font-semibold text-slate-900">Description <span class="font-normal text-slate-500">Optional</span></label><textarea id="create-route-description" name="description" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">{{ old('description') }}</textarea></div>
                        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950"><span class="font-semibold">Safe by default:</span> this automation stays off until you add its action or actions and turn it on.</div>
                        <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end"><button type="button" x-on:click="closeCreate()" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">Cancel</button><button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white" x-text="createKind === 'automatic_behavior' ? 'Create and choose action' : 'Create and build Route'"></button></div>
                    </form>
                </section>
            </div>
        </template>

        @foreach($routeEditors as $editor)
            @include('crm.flow-routes.partials.editor-modal', ['editor' => $editor, 'editorOptions' => $editorOptions])
        @endforeach
    </div>
</x-layouts.crm>