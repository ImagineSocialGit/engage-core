<x-layouts.crm
    :title="$flowRoute->name"
    heading="Routes"
    :subheading="'Edit the Points that make up '.$flowRoute->name.'.'"
    module="flow_routes"
>
    <div class="space-y-6">
        @include('crm.flow-routes.partials.navigation')

        @if(session('status'))
            <x-ui.feedback.alert type="success">
                {{ session('status') }}
            </x-ui.feedback.alert>
        @endif

        @if($errors->any())
            <x-ui.feedback.alert type="error">
                <div class="space-y-1">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            </x-ui.feedback.alert>
        @endif

        <section class="rounded-3xl border border-orange-200 bg-white/90 p-6 shadow-sm sm:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">
                            Route editor
                        </p>

                        @if($flowRoute->is_customized)
                            <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-900 ring-1 ring-orange-200">
                                Customized
                            </span>
                        @endif
                    </div>

                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                        {{ $flowRoute->name }}
                    </h2>

                    <p class="mt-3 text-sm font-semibold text-slate-900">
                        {{ $route['trigger_summary'] }}
                    </p>

                    @if($flowRoute->description)
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                            {{ $flowRoute->description }}
                        </p>
                    @endif
                </div>

                <a
                    href="{{ route('crm.flow-routes.index') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                >
                    Back to Routes
                </a>
            </div>
        </section>

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
            <div class="border-b border-orange-100 p-6 sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                    Route flow
                </h2>

                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                    Points run from top to bottom. Move them to change order, edit their configuration, or remove them from the active Route.
                </p>
            </div>

            <div class="space-y-4 p-6 sm:p-8">
                @forelse($points as $index => $point)
                    @php
                        $presented = collect($route['presented_points'])->firstWhere('key', $point->key);
                        $moduleKey = $presented['module_key'] ?? ($point->capability?->module_key ?: 'flow_routes');
                    @endphp

                    <article
                        id="point-{{ $point->id }}"
                        class="rounded-2xl p-5 ring-1 {{ module_tone($moduleKey, 'item') }}"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-white text-xs font-bold ring-1 {{ module_tone($moduleKey, 'text') }}">
                                        {{ $index + 1 }}
                                    </span>

                                    <h3 class="font-semibold text-slate-950">
                                        {{ $point->name }}
                                    </h3>

                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone($moduleKey, 'badge') }}">
                                        {{ $point->capability?->name ?? \Illuminate\Support\Str::headline($point->type) }}
                                    </span>
                                </div>

                                <p class="mt-3 text-sm leading-6 text-slate-800">
                                    {{ $presented['summary'] ?? $point->description ?? $point->name }}
                                </p>

                                @foreach(($presented['condition_summaries'] ?? []) as $conditionSummary)
                                    <p class="mt-1 text-xs leading-5 text-slate-700">
                                        {{ $conditionSummary }}
                                    </p>
                                @endforeach
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-2">
                                <form method="POST" action="{{ route('crm.flow-routes.points.move-up', [$flowRoute, $point]) }}">
                                    @csrf
                                    @method('PATCH')

                                    <button
                                        type="submit"
                                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                        @disabled($loop->first)
                                    >
                                        Move up
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('crm.flow-routes.points.move-down', [$flowRoute, $point]) }}">
                                    @csrf
                                    @method('PATCH')

                                    <button
                                        type="submit"
                                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                        @disabled($loop->last)
                                    >
                                        Move down
                                    </button>
                                </form>
                            </div>
                        </div>

                        <details class="group mt-4 border-t border-black/10 pt-4">
                            <summary class="cursor-pointer text-sm font-semibold text-slate-800 marker:text-slate-500 hover:text-slate-950">
                                Edit Point
                            </summary>

                            <form
                                method="POST"
                                action="{{ route('crm.flow-routes.points.update', [$flowRoute, $point]) }}"
                                class="mt-4 space-y-4 rounded-2xl bg-white/80 p-4 ring-1 ring-black/5"
                            >
                                @csrf
                                @method('PATCH')

                                <div>
                                    <label for="point-name-{{ $point->id }}" class="text-sm font-semibold text-slate-900">
                                        Point label
                                    </label>

                                    <input
                                        id="point-name-{{ $point->id }}"
                                        name="name"
                                        type="text"
                                        value="{{ old('name', $point->name) }}"
                                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                    >
                                </div>

                                @include('crm.flow-routes.partials.point-fields', [
                                    'fields' => $presented['fields'] ?? [],
                                    'fieldSuffix' => 'edit-'.$point->id,
                                ])

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                                >
                                    Save Point
                                </button>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('crm.flow-routes.points.destroy', [$flowRoute, $point]) }}"
                                class="mt-3"
                                onsubmit="return confirm('Remove this Point from the active Route?');"
                            >
                                @csrf
                                @method('DELETE')

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                                >
                                    Remove Point
                                </button>
                            </form>
                        </details>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-6 text-sm text-amber-950">
                        This Route has no active Points. Add one below to define what it should do.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-3xl border border-orange-200 bg-white/90 shadow-sm">
            <div class="border-b border-orange-100 p-6 sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                    Add a Point
                </h2>

                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                    Choose a capability that is available in this client. Each Point type includes a practical tip and a few example use cases.
                </p>
            </div>

            <div class="grid gap-4 p-6 lg:grid-cols-2 sm:p-8">
                @forelse($capabilities as $capability)
                    <details class="group rounded-2xl p-5 ring-1 {{ module_tone($capability['module_key'], 'item') }}">
                        <summary class="cursor-pointer list-none">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold text-slate-950">
                                            {{ $capability['name'] }}
                                        </h3>

                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone($capability['module_key'], 'badge') }}">
                                            {{ config('modules.modules.'.$capability['module_key'].'.name', \Illuminate\Support\Str::headline($capability['module_key'])) }}
                                        </span>
                                    </div>

                                    <p class="mt-2 text-sm leading-6 text-slate-700">
                                        {{ $capability['description'] }}
                                    </p>
                                </div>

                                <span class="text-sm font-semibold text-slate-800 group-open:hidden">Configure</span>
                                <span class="hidden text-sm font-semibold text-slate-800 group-open:inline">Close</span>
                            </div>
                        </summary>

                        <div class="mt-5 border-t border-black/10 pt-5">
                            @if($capability['tip'])
                                <div class="rounded-xl bg-white/80 p-3 text-sm leading-6 text-slate-800 ring-1 ring-black/5">
                                    <span class="font-semibold">Tip:</span> {{ $capability['tip'] }}
                                </div>
                            @endif

                            @if($capability['use_cases'] !== [])
                                <div class="mt-4">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-700">
                                        Common use cases
                                    </p>

                                    <ul class="mt-2 space-y-1 text-sm leading-6 text-slate-700">
                                        @foreach($capability['use_cases'] as $useCase)
                                            <li class="flex gap-2">
                                                <span aria-hidden="true">•</span>
                                                <span>{{ $useCase }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form
                                method="POST"
                                action="{{ route('crm.flow-routes.points.store', $flowRoute) }}"
                                class="mt-5 space-y-4 rounded-2xl bg-white/80 p-4 ring-1 ring-black/5"
                            >
                                @csrf

                                <input type="hidden" name="capability_id" value="{{ $capability['id'] }}">

                                <div>
                                    <label for="point-name-{{ $capability['id'] }}" class="text-sm font-semibold text-slate-900">
                                        Point label <span class="font-normal text-slate-600">(optional)</span>
                                    </label>

                                    <input
                                        id="point-name-{{ $capability['id'] }}"
                                        name="name"
                                        type="text"
                                        value="{{ old('name') }}"
                                        placeholder="{{ $capability['name'] }}"
                                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                    >
                                </div>

                                @include('crm.flow-routes.partials.point-fields', [
                                    'fields' => $capability['fields'] ?? [],
                                    'fieldSuffix' => 'create-'.$capability['id'],
                                ])

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                                >
                                    Add Point
                                </button>
                            </form>
                        </div>
                    </details>
                @empty
                    <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-6 text-sm text-amber-950 lg:col-span-2">
                        No authorable capabilities are currently available. Sync FlowRoute capabilities and confirm the required modules are enabled.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>
