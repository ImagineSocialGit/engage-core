<x-layouts.crm
    title="Automatic Follow-ups"
    heading="Automatic Follow-ups"
    subheading="Choose what should happen automatically when {{ $contactLabel['singular'] }} statuses or activity change."
>
    @php
        $firstStatusKey = $contactStatusBindings->first()['status']->key ?? '';
        $firstActivityModuleKey = $automationEventGroups->first()['key'] ?? '';
    @endphp

    <div
        class="space-y-6"
        x-data="{
            tab: 'status',
            selectedStatus: @js($firstStatusKey),
            selectedActivityModule: @js($firstActivityModuleKey),
        }"
    >
        @if(session('status'))
            <x-ui.feedback.alert type="success">
                {{ session('status') }}
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">
                    Follow-up rules
                </p>

                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    When this happens, what should happen next?
                </h2>

                <p class="mt-3 text-sm leading-6 text-slate-600">
                    Use this page to choose the automatic follow-ups Engage Core runs after familiar business moments, such as a
                    {{ $contactLabel['singular'] }} moving to a status or someone attending a webinar.
                </p>
            </div>

            <div class="mt-6 flex flex-wrap gap-2 rounded-2xl bg-slate-100 p-1 text-sm font-semibold">
                <button
                    type="button"
                    x-on:click="tab = 'status'"
                    class="rounded-xl px-4 py-2 transition"
                    x-bind:class="tab === 'status' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                >
                    By Status
                </button>

                <button
                    type="button"
                    x-on:click="tab = 'activity'"
                    class="rounded-xl px-4 py-2 transition"
                    x-bind:class="tab === 'activity' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                >
                    By Activity
                </button>
            </div>
        </section>

        <section x-show="tab === 'status'" class="space-y-4">
            <div class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 p-6">
                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-end">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                                Status follow-ups
                            </h2>

                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                Choose a status, then choose the one follow-up that should start when a
                                {{ $contactLabel['singular'] }} moves into that status.
                            </p>
                        </div>

                        <div>
                            <label for="status-selector" class="text-sm font-semibold text-slate-800">
                                Status
                            </label>

                            <select
                                id="status-selector"
                                x-model="selectedStatus"
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
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
                        @endphp

                        <form
                            method="POST"
                            action="{{ route('crm.flow-routes.bindings.update') }}"
                            x-show="selectedStatus === @js($status->key)"
                            class="space-y-6"
                        >
                            @csrf
                            @method('PATCH')

                            <input type="hidden" name="trigger_type" value="{{ \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_CONTACT_STATUS }}">
                            <input type="hidden" name="trigger_key" value="{{ $status->key }}">

                            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-xl font-semibold tracking-tight text-slate-950">
                                                {{ $status->name }}
                                            </h3>

                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                                Status
                                            </span>
                                        </div>

                                        @if($status->description)
                                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                                {{ $status->description }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Current follow-up
                                        </p>

                                        @if($selectedRoute)
                                            <div class="mt-3 space-y-3">
                                                <div>
                                                    <p class="font-semibold text-slate-950">
                                                        {{ $selectedRoute['name'] }}
                                                    </p>

                                                    @if($selectedRoute['description'])
                                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                                            {{ $selectedRoute['description'] }}
                                                        </p>
                                                    @endif
                                                </div>

                                                @if(count($selectedRoute['summary_points']) > 0)
                                                    <div>
                                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                            What will happen
                                                        </p>

                                                        <ul class="mt-2 space-y-2 text-sm text-slate-700">
                                                            @foreach($selectedRoute['summary_points'] as $summaryPoint)
                                                                <li class="flex gap-2">
                                                                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span>
                                                                    <span>{{ $summaryPoint }}</span>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                                No automatic follow-up is selected for this status.
                                            </p>
                                        @endif

                                        @if($row['active_binding_count'] > 1)
                                            <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                                                More than one active selection was found. Engage Core is currently using the newest one.
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-4 rounded-2xl border border-slate-200 p-4">
                                    <div>
                                        <label for="status-route-{{ $status->id }}" class="text-sm font-semibold text-slate-800">
                                            Change follow-up
                                        </label>

                                        <select
                                            id="status-route-{{ $status->id }}"
                                            name="flow_route_id"
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                                            @disabled($availableRoutes->isEmpty())
                                        >
                                            @if($availableRoutes->isEmpty())
                                                <option value="">No follow-ups available yet</option>
                                            @else
                                                @foreach($availableRoutes as $route)
                                                    <option value="{{ $route['id'] }}" @selected((int) $selectedRouteId === (int) $route['id'])>
                                                        {{ $route['name'] }}
                                                    </option>
                                                @endforeach
                                            @endif
                                        </select>
                                    </div>

                                    <p class="text-sm leading-6 text-slate-600">
                                        Only one status follow-up can run for this status. Saving replaces the current selection.
                                    </p>

                                    <button
                                        type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                                        @disabled($availableRoutes->isEmpty())
                                    >
                                        Save Status Follow-up
                                    </button>

                                    @if($availableRoutes->isEmpty())
                                        <p class="text-sm text-slate-500">
                                            No active follow-up routes are available for {{ $status->name }} yet.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </form>
                    @empty
                        <div class="py-10 text-center text-sm text-slate-500">
                            No active statuses are available.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section x-show="tab === 'activity'" class="space-y-4">
            <div class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 p-6">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                        Activity follow-ups
                    </h2>

                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                        Choose what should happen automatically after activity in enabled modules. More than one follow-up can run from the same activity.
                    </p>

                    @if($automationEventGroups->isNotEmpty())
                        <div class="mt-5 flex flex-wrap gap-2 rounded-2xl bg-slate-100 p-1 text-sm font-semibold">
                            @foreach($automationEventGroups as $group)
                                <button
                                    type="button"
                                    x-on:click="selectedActivityModule = @js($group['key'])"
                                    class="rounded-xl px-4 py-2 transition"
                                    x-bind:class="selectedActivityModule === @js($group['key']) ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
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
                                @endphp

                                <form
                                    method="POST"
                                    action="{{ route('crm.flow-routes.bindings.update') }}"
                                    class="rounded-2xl border border-slate-200 p-4"
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

                                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                                {{ $row['description'] }}
                                            </p>

                                            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                {{ count($selectedRouteIds) }} selected
                                            </p>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            @forelse($availableRoutes as $route)
                                                <label class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm transition hover:border-slate-300 hover:bg-white">
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

                                                        @if($route['description'])
                                                            <span class="mt-1 block text-sm leading-5 text-slate-600">
                                                                {{ $route['description'] }}
                                                            </span>
                                                        @endif

                                                        @if(count($route['summary_points']) > 0)
                                                            <span class="mt-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                                What it does
                                                            </span>

                                                            <span class="mt-1 block space-y-1 text-xs leading-5 text-slate-600">
                                                                @foreach($route['summary_points'] as $summaryPoint)
                                                                    <span class="block">• {{ $summaryPoint }}</span>
                                                                @endforeach
                                                            </span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @empty
                                                <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                                                    No active follow-ups are available for this activity yet.
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
                        <div class="py-10 text-center text-sm text-slate-500">
                            No activity follow-ups are available yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-layouts.crm>
