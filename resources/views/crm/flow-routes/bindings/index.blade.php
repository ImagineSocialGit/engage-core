<x-layouts.crm
    title="Route Assignments"
    heading="Routes"
    subheading="Choose which Routes run when {{ $contactLabel['singular'] }} statuses or business activity change."
    module="flow_routes"
>
    @php
        $firstStatusKey = $contactStatusBindings->first()['status']->key ?? '';
        $statusKeys = $contactStatusBindings->map(fn ($row) => $row['status']->key)->all();
        $requestedStatusKey = (string) request()->query('status', '');
        $initialStatusKey = in_array($requestedStatusKey, $statusKeys, true) ? $requestedStatusKey : $firstStatusKey;

        $firstActivityModuleKey = $automationEventGroups->first()['key'] ?? '';
        $activityModuleKeys = $automationEventGroups->pluck('key')->all();
        $requestedActivityModuleKey = (string) request()->query('module', '');
        $initialActivityModuleKey = in_array($requestedActivityModuleKey, $activityModuleKeys, true)
            ? $requestedActivityModuleKey
            : $firstActivityModuleKey;

        $requestedTab = (string) request()->query('tab', 'status');
        $initialTab = in_array($requestedTab, ['status', 'activity'], true) ? $requestedTab : 'status';
    @endphp

    <div
        class="space-y-6"
        x-data="{
            tab: @js($initialTab),
            selectedStatus: @js($initialStatusKey),
            selectedActivityModule: @js($initialActivityModuleKey),
            focusedTarget: null,
            focusHashTarget() {
                const targetId = window.location.hash.replace('#', '');

                if (! targetId) return;

                this.focusedTarget = targetId;

                this.$nextTick(() => {
                    const target = document.getElementById(targetId);

                    if (! target) return;

                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    window.setTimeout(() => {
                        if (this.focusedTarget === targetId) {
                            this.focusedTarget = null;
                        }
                    }, 1800);
                });
            },
        }"
        x-init="focusHashTarget()"
    >
        @include('crm.flow-routes.partials.navigation')

        @if(session('status'))
            <x-ui.feedback.alert type="success">
                {{ session('status') }}
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-3xl border border-orange-200 bg-white/90 p-5 shadow-sm sm:p-6">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">
                    Route assignments
                </p>

                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                    Choose what runs automatically
                </h2>

                <p class="mt-3 text-sm leading-6 text-slate-700">
                    Use this page to choose which Routes run after familiar business moments, such as a
                    {{ $contactLabel['singular'] }} moving to a status or someone attending a webinar.
                </p>
            </div>

            <div class="mt-6 flex flex-wrap gap-2 rounded-2xl bg-orange-50 p-1 text-sm font-semibold ring-1 ring-orange-200">
                <button
                    type="button"
                    x-on:click="tab = 'status'"
                    class="rounded-xl px-4 py-2 transition"
                    x-bind:class="tab === 'status' ? 'bg-white text-orange-950 shadow-sm ring-1 ring-orange-200' : 'text-slate-700 hover:text-slate-900'"
                >
                    By Status
                </button>

                <button
                    type="button"
                    x-on:click="tab = 'activity'"
                    class="rounded-xl px-4 py-2 transition"
                    x-bind:class="tab === 'activity' ? 'bg-white text-orange-950 shadow-sm ring-1 ring-orange-200' : 'text-slate-700 hover:text-slate-900'"
                >
                    By Activity
                </button>
            </div>
        </section>

        <section x-show="tab === 'status'" class="space-y-4">
            <div class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
                <div class="border-b border-orange-100 p-6">
                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-end">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                                Status assignments
                            </h2>

                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                                Choose the one Route that should start when a {{ $contactLabel['singular'] }} moves into a status.
                            </p>
                        </div>

                        <div>
                            <label for="status-selector" class="text-sm font-semibold text-slate-800">
                                Status
                            </label>

                            <select
                                id="status-selector"
                                x-model="selectedStatus"
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200"
                            >
                                @foreach($contactStatusBindings as $row)
                                    <option value="{{ $row['status']->key }}">
                                        {{ $row['status']->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    @forelse($contactStatusBindings as $row)
                        @php
                            $status = $row['status'];
                            $availableRoutes = $row['available_routes'];
                            $selectedRouteId = $row['selected_route_id'];
                            $selectedRoute = $row['selected_route'];
                            $targetId = 'status-'.\Illuminate\Support\Str::slug($status->key);
                        @endphp

                        <form
                            id="{{ $targetId }}"
                            method="POST"
                            action="{{ route('crm.flow-routes.bindings.update') }}"
                            x-show="selectedStatus === @js($status->key)"
                            class="rounded-2xl p-5 ring-1 transition duration-300 {{ module_tone('workflow', 'item') }}"
                            x-bind:class="focusedTarget === @js($targetId) ? 'scale-[1.01] !bg-orange-100 ring-2 ring-orange-500 shadow-md' : ''"
                        >
                            @csrf
                            @method('PATCH')

                            <input type="hidden" name="trigger_type" value="{{ \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_CONTACT_STATUS }}">
                            <input type="hidden" name="trigger_key" value="{{ $status->key }}">

                            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem] xl:items-start">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-xl font-semibold tracking-tight text-slate-950">
                                            {{ $status->name }}
                                        </h3>

                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('workflow', 'badge') }}">
                                            Status
                                        </span>
                                    </div>

                                    @if($status->description)
                                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-700">
                                            {{ $status->description }}
                                        </p>
                                    @endif

                                    <div class="mt-4">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Current assignment
                                        </p>

                                        @if($selectedRoute)
                                            <p class="mt-1 font-semibold text-slate-950">
                                                {{ $selectedRoute['name'] }}
                                            </p>

                                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-700">
                                                {{ $selectedRoute['compact_summary'] }}
                                            </p>
                                        @else
                                            <p class="mt-1 text-sm leading-6 text-slate-700">
                                                No Route is assigned to this status.
                                            </p>
                                        @endif

                                        @if($row['active_binding_count'] > 1)
                                            <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">
                                                More than one active selection was found. The newest active selection is currently being used.
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <div>
                                        <label for="status-route-{{ $status->id }}" class="text-sm font-semibold text-slate-800">
                                            Assigned Route
                                        </label>

                                        <select
                                            id="status-route-{{ $status->id }}"
                                            name="flow_route_id"
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                            @disabled($availableRoutes->isEmpty())
                                        >
                                            @if($availableRoutes->isEmpty())
                                                <option value="">No Routes available yet</option>
                                            @else
                                                @foreach($availableRoutes as $route)
                                                    <option value="{{ $route['id'] }}" @selected((int) $selectedRouteId === (int) $route['id'])>
                                                        {{ $route['name'] }}
                                                    </option>
                                                @endforeach
                                            @endif
                                        </select>
                                    </div>

                                    <button
                                        type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                                        @disabled($availableRoutes->isEmpty())
                                    >
                                        Save Assignment
                                    </button>

                                    @if($availableRoutes->isEmpty())
                                        <p class="text-sm text-slate-700">
                                            No active Routes are available for {{ $status->name }} yet.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </form>
                    @empty
                        <div class="py-10 text-center text-sm text-slate-700">
                            No active statuses are available.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section x-show="tab === 'activity'" class="space-y-4">
            <div class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
                <div class="border-b border-orange-100 p-6">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                        Activity assignments
                    </h2>

                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        Choose which Routes run after activity in enabled modules. More than one Route can run from the same activity.
                    </p>

                    @if($automationEventGroups->isNotEmpty())
                        <div class="mt-5 flex flex-wrap gap-2 rounded-2xl bg-slate-100 p-1 text-sm font-semibold">
                            @foreach($automationEventGroups as $group)
                                <button
                                    type="button"
                                    x-on:click="selectedActivityModule = @js($group['key'])"
                                    class="rounded-xl px-4 py-2 transition"
                                    x-bind:class="selectedActivityModule === @js($group['key'])
                                        ? @js(module_tone($group['key'], 'badge').' shadow-sm')
                                        : 'text-slate-700 hover:text-slate-900'"
                                >
                                    {{ $group['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="p-6">
                    @forelse($automationEventGroups as $group)
                        <div x-show="selectedActivityModule === @js($group['key'])" class="space-y-4">
                            @foreach($group['events'] as $row)
                                @php
                                    $eventKey = $row['event_key'];
                                    $availableRoutes = $row['available_routes'];
                                    $selectedRouteIds = $row['selected_route_ids'];
                                    $targetId = 'event-'.\Illuminate\Support\Str::of($eventKey)->replace('.', '-')->slug();
                                @endphp

                                <form
                                    id="{{ $targetId }}"
                                    method="POST"
                                    action="{{ route('crm.flow-routes.bindings.update') }}"
                                    class="rounded-2xl p-4 ring-1 transition duration-300 {{ module_tone($group['key'], 'item') }}"
                                    x-bind:class="focusedTarget === @js((string) $targetId) ? 'scale-[1.01] !bg-orange-100 ring-2 ring-orange-500 shadow-md' : ''"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <input type="hidden" name="trigger_type" value="{{ \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_AUTOMATION_EVENT }}">
                                    <input type="hidden" name="trigger_key" value="{{ $eventKey }}">

                                    <div class="grid gap-5 xl:grid-cols-[minmax(0,18rem)_minmax(0,1fr)_10rem] xl:items-start">
                                        <div>
                                            <h3 class="font-semibold text-slate-950">
                                                {{ $row['label'] }}
                                            </h3>

                                            <p class="mt-1 text-sm leading-6 text-slate-700">
                                                {{ $row['description'] }}
                                            </p>

                                            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-700">
                                                {{ count($selectedRouteIds) }} selected
                                            </p>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            @forelse($availableRoutes as $route)
                                                @php($point = $route['presented_points'][0] ?? null)

                                                <label class="flex gap-3 rounded-xl bg-white/90 px-4 py-3 text-sm shadow-sm ring-1 {{ $point ? module_tone($point['module_key'], 'item') : 'ring-slate-200' }}">
                                                    <input
                                                        type="checkbox"
                                                        name="flow_route_ids[]"
                                                        value="{{ $route['id'] }}"
                                                        @checked(in_array((int) $route['id'], $selectedRouteIds, true))
                                                        class="mt-1 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                    >

                                                    <span class="min-w-0">
                                                        <span class="block font-semibold text-slate-900">
                                                            {{ $route['name'] }}
                                                        </span>

                                                        <span class="mt-1 block text-sm leading-5 text-slate-700">
                                                            {{ $route['compact_summary'] }}
                                                        </span>

                                                        @if($route['has_campaign_enrollment'])
                                                            <span class="mt-2 block text-xs leading-5 text-slate-700">
                                                                Messages are sent only when communication permissions and delivery rules allow.
                                                            </span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @empty
                                                <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-700">
                                                    No active Routes are available for this activity yet.
                                                </div>
                                            @endforelse
                                        </div>

                                        <div class="flex justify-start xl:justify-end">
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                                                @disabled($availableRoutes->isEmpty())
                                            >
                                                Save
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            @endforeach
                        </div>
                    @empty
                        <div class="py-10 text-center text-sm text-slate-700">
                            No activity-triggered Routes are available yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-layouts.crm>
