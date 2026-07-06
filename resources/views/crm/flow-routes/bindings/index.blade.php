<x-layouts.crm
    title="Route Trigger Bindings"
    heading="Route Trigger Bindings"
    subheading="Choose which available routes run for workflow statuses and automation events."
>
    <div class="space-y-8">
        @if(session('status'))
            <x-ui.feedback.alert type="success">
                {{ session('status') }}
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">Contact status routes</h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-600">
                            Select the route that should start when a contact moves into each workflow status. These are single-selection bindings for now.
                        </p>
                    </div>

                    <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        One route per status
                    </span>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($contactStatusBindings as $row)
                    @php
                        $status = $row['status'];
                        $availableRoutes = $row['available_routes'];
                        $selectedRouteId = $row['selected_route_id'];
                    @endphp

                    <form method="POST" action="{{ route('crm.flow-routes.bindings.update') }}" class="grid gap-4 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,28rem)_auto] lg:items-center">
                        @csrf
                        @method('PATCH')

                        <input type="hidden" name="trigger_type" value="{{ \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_CONTACT_STATUS }}">
                        <input type="hidden" name="trigger_key" value="{{ $status->key }}">

                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-950">{{ $status->name }}</h3>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{{ $status->key }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $availableRoutes->count() }} available {{ Str::plural('route', $availableRoutes->count()) }}
                                @if($row['active_binding_count'] > 1)
                                    · {{ $row['active_binding_count'] }} active bindings found; the newest one is currently selected.
                                @endif
                            </p>
                        </div>

                        <div>
                            <label class="sr-only" for="status-route-{{ $status->id }}">Selected route for {{ $status->name }}</label>
                            <select
                                id="status-route-{{ $status->id }}"
                                name="flow_route_id"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                                @disabled($availableRoutes->isEmpty())
                            >
                                @if($availableRoutes->isEmpty())
                                    <option value="">No active routes available</option>
                                @else
                                    @foreach($availableRoutes as $route)
                                        <option value="{{ $route->id }}" @selected((int) $selectedRouteId === (int) $route->id)>
                                            {{ $route->name }} · v{{ $route->version }}
                                        </option>
                                    @endforeach
                                @endif
                            </select>
                        </div>

                        <div class="flex justify-start lg:justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                                @disabled($availableRoutes->isEmpty())
                            >
                                Save
                            </button>
                        </div>
                    </form>
                @empty
                    <div class="px-6 py-10 text-center text-sm text-slate-500">
                        No active contact statuses are available.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">Automation event routes</h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-600">
                            Choose all routes that should run when a supported automation event is recorded. Multiple selected routes can run for the same event.
                        </p>
                    </div>

                    <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Multiple routes allowed
                    </span>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($automationEventBindings as $row)
                    @php
                        $eventKey = $row['event_key'];
                        $availableRoutes = $row['available_routes'];
                        $selectedRouteIds = $row['selected_route_ids'];
                    @endphp

                    <form method="POST" action="{{ route('crm.flow-routes.bindings.update') }}" class="grid gap-4 px-6 py-5 lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)_auto] lg:items-start">
                        @csrf
                        @method('PATCH')

                        <input type="hidden" name="trigger_type" value="{{ \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_AUTOMATION_EVENT }}">
                        <input type="hidden" name="trigger_key" value="{{ $eventKey }}">

                        <div>
                            <h3 class="font-semibold text-slate-950">{{ $eventKey }}</h3>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ count($selectedRouteIds) }} selected of {{ $availableRoutes->count() }} available
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach($availableRoutes as $route)
                                <label class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm transition hover:border-slate-300 hover:bg-white">
                                    <input
                                        type="checkbox"
                                        name="flow_route_ids[]"
                                        value="{{ $route->id }}"
                                        @checked(in_array((int) $route->id, $selectedRouteIds, true))
                                        class="mt-1 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                    >

                                    <span>
                                        <span class="block font-semibold text-slate-900">{{ $route->name }}</span>
                                        <span class="mt-1 block text-xs text-slate-500">
                                            {{ $route->key }} · v{{ $route->version }}
                                            @if($route->owner_group)
                                                · {{ $route->owner_group }}
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="flex justify-start lg:justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                                @disabled($availableRoutes->isEmpty())
                            >
                                Save
                            </button>
                        </div>
                    </form>
                @empty
                    <div class="px-6 py-10 text-center text-sm text-slate-500">
                        No active automation-event routes are available.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>
