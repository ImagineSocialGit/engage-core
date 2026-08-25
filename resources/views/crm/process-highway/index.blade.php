@php
    $subjects = collect($highway['subjects'] ?? []);
    $highways = collect($highway['highways'] ?? []);
    $qualifierFilters = collect($highway['qualifier_filters'] ?? []);
    $initialSubject = (string) (($subjects->first()['key'] ?? null) ?: 'contacts');
    $laneOptions = $subjects
        ->flatMap(fn (array $subject): array => array_map(
            fn (array $lane): array => [
                ...$lane,
                'subject_key' => $subject['key'],
                'subject_label' => $subject['label'],
            ],
            $subject['lanes'] ?? [],
        ))
        ->values();
    $qualifierSelection = $qualifierFilters
        ->mapWithKeys(fn (array $filter): array => [$filter['key'] => ''])
        ->all();
    $filterableHighways = $highways
        ->map(fn (array $item): array => [
            'key' => $item['key'],
            'subject_key' => $item['subject_key'],
            'lane_key' => $item['lane_key'],
            'qualifiers' => $item['qualifiers'],
            'search_text' => $item['search_text'],
        ])
        ->values()
        ->all();
    $mechanismBadges = [
        'campaigns' => 'Campaign',
        'flow_routes' => 'Flow Route',
    ];
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="core"
>
    <div
        class="space-y-6"
        data-process-highway
        x-data="{
            items: @js($filterableHighways),
            subject: @js($initialSubject),
            lane: '',
            laneOptions: @js($laneOptions->all()),
            query: '',
            qualifiers: @js($qualifierSelection),
            ramp: null,
            hasAudienceSelection() {
                return this.lane !== ''
                    || Object.values(this.qualifiers).some((value) => value !== '');
            },
            matches(item) {
                if (! this.hasAudienceSelection()) {
                    return false;
                }

                if (this.subject && item.subject_key !== this.subject) {
                    return false;
                }

                if (this.lane && item.lane_key !== this.lane) {
                    return false;
                }

                const query = this.query.trim().toLowerCase();

                if (query && ! item.search_text.includes(query)) {
                    return false;
                }

                return Object.entries(this.qualifiers).every(([key, value]) => {
                    return ! value || (item.qualifiers[key] || []).includes(value);
                });
            },
            visibleCount() {
                return this.items.filter((item) => this.matches(item)).length;
            },
            clearFilters() {
                this.lane = '';
                this.query = '';
                Object.keys(this.qualifiers).forEach((key) => this.qualifiers[key] = '');
            },
            openRamp(ramp) {
                this.ramp = ramp;
            },
            closeRamp() {
                this.ramp = null;
            },
        }"
        x-on:keydown.escape.window="closeRamp()"
    >
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Business process map</p>
            <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                Choose an audience to see what applies
            </h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                The map shows why contacts enter a process, which Campaigns or Flow Routes apply, and what each mechanism can cause.
            </p>
        </section>

        @if(($highway['highway_count'] ?? 0) === 0)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">No configured business processes yet</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Processes appear automatically as enabled features contribute contact entrances and mechanisms.
                </p>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">Audience filters</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Select at least one scope or contact fact before the map is shown.
                        </p>
                    </div>

                    <div class="flex items-center gap-3 text-sm text-slate-600">
                        <span x-show="hasAudienceSelection()">
                            <strong class="font-semibold text-slate-950" x-text="visibleCount()"></strong> shown
                        </span>
                        <button
                            type="button"
                            x-cloak
                            x-show="hasAudienceSelection() || query.trim() !== ''"
                            x-on:click="clearFilters()"
                            class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 transition hover:text-slate-950"
                        >
                            Clear filters
                        </button>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label class="block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Subject</span>
                        <select
                            x-model="subject"
                            x-on:change="lane = ''"
                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                        >
                            @foreach($subjects as $subject)
                                <option value="{{ $subject['key'] }}">{{ $subject['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Contact scope</span>
                        <select
                            x-model="lane"
                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                        >
                            <option value="">Choose a scope</option>
                            <template x-for="option in laneOptions.filter((option) => option.subject_key === subject)" :key="option.key">
                                <option :value="option.key" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>

                    @foreach($qualifierFilters as $filter)
                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">{{ $filter['label'] }}</span>
                            <select
                                x-model="qualifiers['{{ $filter['key'] }}']"
                                class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                            >
                                <option value="">Choose {{ strtolower($filter['label']) }}</option>
                                @foreach($filter['options'] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach

                    <label class="block md:col-span-2 xl:col-span-4">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Refine visible processes</span>
                        <input
                            type="search"
                            x-model.debounce.200ms="query"
                            x-bind:disabled="! hasAudienceSelection()"
                            placeholder="Search process names or outcomes"
                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900 shadow-sm placeholder:text-slate-400 disabled:cursor-not-allowed disabled:bg-slate-100 focus:border-slate-500 focus:ring-slate-500"
                        >
                    </label>
                </div>
            </section>

            <section
                x-show="! hasAudienceSelection()"
                data-process-highway-awaiting-filter
                class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm"
            >
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M7 12h10m-7 6h4" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-slate-950">Select an audience to begin</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Nothing is shown until at least one contact scope or fact is selected.
                </p>
            </section>

            <div x-cloak x-show="hasAudienceSelection()" class="space-y-6" aria-live="polite">
                @foreach($highways as $businessHighway)
                    <article
                        x-show="matches(@js([
                            'key' => $businessHighway['key'],
                            'subject_key' => $businessHighway['subject_key'],
                            'lane_key' => $businessHighway['lane_key'],
                            'qualifiers' => $businessHighway['qualifiers'],
                            'search_text' => $businessHighway['search_text'],
                        ]))"
                        x-transition.opacity
                        data-business-highway="{{ $businessHighway['key'] }}"
                        class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                    >
                        <header class="border-b border-slate-200 bg-slate-50/70 px-5 py-5 text-center sm:px-7">
                            <div class="flex flex-wrap items-center justify-center gap-2">
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-300">
                                    {{ $businessHighway['lane_label'] }}
                                </span>
                                @if($businessHighway['state'] !== 'active')
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-semibold ring-1',
                                        'bg-amber-50 text-amber-900 ring-amber-200' => $businessHighway['state'] === 'needs_configuration',
                                        'bg-slate-100 text-slate-600 ring-slate-200' => $businessHighway['state'] === 'off',
                                    ])>
                                        {{ $businessHighway['state_label'] }}
                                    </span>
                                @endif
                            </div>
                            <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-950">
                                {{ $businessHighway['name'] }}
                            </h2>
                        </header>

                        <div class="p-5 sm:p-7">
                            <section aria-label="Entry ramps" class="mx-auto max-w-4xl text-center">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Entry ramps</p>
                                <div class="mt-3 flex flex-wrap justify-center gap-3">
                                    @foreach($businessHighway['entry_nodes'] as $node)
                                        @php($inspector = $node['inspector'] ?? null)
                                        @php($target = $node['navigation_target'] ?? null)

                                        @if(is_array($inspector))
                                            <button
                                                type="button"
                                                x-on:click="openRamp(@js($inspector))"
                                                data-entry-ramp-inspector="{{ $node['key'] }}"
                                                class="group min-w-44 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-slate-400 hover:shadow-md"
                                            >
                                                <span class="block text-[0.68rem] font-bold uppercase tracking-[0.12em] text-slate-500">
                                                    {{ $inspector['criterion_label'] }}
                                                </span>
                                                <span class="mt-1 flex items-center justify-between gap-3 text-sm font-semibold text-slate-950">
                                                    {{ $inspector['value_label'] }}
                                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400 transition group-hover:text-slate-700" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6" />
                                                    </svg>
                                                </span>
                                            </button>
                                        @elseif(is_array($target))
                                            <a
                                                href="{{ $target['url'] }}"
                                                class="group min-w-44 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-slate-400 hover:shadow-md"
                                            >
                                                <span class="block text-[0.68rem] font-bold uppercase tracking-[0.12em] text-slate-500">Entrance</span>
                                                <span class="mt-1 flex items-center justify-between gap-3 text-sm font-semibold text-slate-950">
                                                    {{ $node['label'] }}
                                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400 transition group-hover:text-slate-700" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6" />
                                                    </svg>
                                                </span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </section>

                            <div class="mx-auto my-4 flex h-10 w-px items-end justify-center bg-slate-300 text-slate-400" aria-hidden="true">
                                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 translate-y-2.5 bg-white" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
                                </svg>
                            </div>

                            <section aria-label="Applicable mechanisms" class="mx-auto max-w-4xl">
                                <p class="text-center text-xs font-bold uppercase tracking-[0.14em] text-slate-500">What applies</p>
                                <div class="mt-3 space-y-4">
                                    @foreach($businessHighway['segments'] as $segment)
                                        @php($segmentTarget = $segment['navigation_target'])
                                        @php($mechanismBadge = $mechanismBadges[$segment['source_key']] ?? null)

                                        <article
                                            data-process-highway-segment="{{ $segment['key'] }}"
                                            data-process-highway-owner="{{ $segment['source_key'] }}"
                                            class="rounded-2xl p-4 ring-1 sm:p-5 {{ module_tone($segment['source_key'], 'panel') }}"
                                        >
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        @if($mechanismBadge !== null)
                                                            <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ module_tone($segment['source_key'], 'badge') }}">
                                                                {{ $mechanismBadge }}
                                                            </span>
                                                        @endif
                                                        @if($segment['state'] !== 'active')
                                                            <span class="rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-black/5">
                                                                {{ $segment['state_label'] }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <h3 class="mt-2 text-base font-semibold leading-6 text-slate-950">{{ $segment['name'] }}</h3>
                                                    @if($segment['description'])
                                                        <p class="mt-1 text-sm leading-5 text-slate-600">{{ $segment['description'] }}</p>
                                                    @endif
                                                </div>

                                                <a
                                                    href="{{ $segmentTarget['url'] }}"
                                                    class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                                                >
                                                    {{ $segmentTarget['label'] }}
                                                </a>
                                            </div>

                                            @if($segment['mechanism_outcomes'] !== [])
                                                <div class="mt-4 grid gap-2 border-t border-black/5 pt-4 sm:grid-cols-2">
                                                    @foreach($segment['mechanism_outcomes'] as $outcome)
                                                        <a
                                                            href="{{ $outcome['node']['navigation_target']['url'] }}"
                                                            data-process-highway-outcome="{{ $outcome['node']['key'] }}"
                                                            class="rounded-xl border border-slate-200 bg-white/80 px-3 py-3 transition hover:border-slate-300 hover:bg-white"
                                                        >
                                                            <span class="block text-[0.68rem] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                                {{ $outcome['edge']['label'] ?: 'Can lead to' }}
                                                            </span>
                                                            <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if($segment['journey_nodes'] !== [])
                                                <div class="mt-4 space-y-3 border-t border-black/5 pt-4">
                                                    @foreach($segment['journey_nodes'] as $node)
                                                        @php($nodeTarget = $node['navigation_target'])
                                                        <div @class([
                                                            'grid gap-3',
                                                            'lg:grid-cols-[minmax(0,1fr)_minmax(13rem,0.72fr)] lg:items-center' => $node['outcomes'] !== [],
                                                        ])>
                                                            <a
                                                                href="{{ $nodeTarget['url'] }}"
                                                                class="rounded-xl border border-black/5 bg-white/70 px-3 py-3 text-sm font-semibold text-slate-900 transition hover:bg-white"
                                                            >
                                                                {{ $node['label'] }}
                                                            </a>

                                                            @if($node['outcomes'] !== [])
                                                                <div class="space-y-2 lg:border-l lg:border-slate-300 lg:pl-3">
                                                                    @foreach($node['outcomes'] as $outcome)
                                                                        <a
                                                                            href="{{ $outcome['node']['navigation_target']['url'] }}"
                                                                            data-process-highway-outcome="{{ $outcome['node']['key'] }}"
                                                                            class="block rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:shadow-sm"
                                                                        >
                                                                            <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                                                {{ $outcome['edge']['label'] ?: 'Can lead to' }}
                                                                            </span>
                                                                            <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
                                                                        </a>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if($segment['additional_outcome_groups'] !== [])
                                                <div class="mt-4 space-y-3 border-t border-black/5 pt-4">
                                                    @foreach($segment['additional_outcome_groups'] as $group)
                                                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(13rem,0.72fr)] lg:items-center">
                                                            <div class="rounded-xl border border-black/5 bg-white/70 px-3 py-3 text-sm font-semibold text-slate-900">
                                                                {{ $group['trigger_node']['label'] }}
                                                            </div>
                                                            <div class="space-y-2 lg:border-l lg:border-slate-300 lg:pl-3">
                                                                @foreach($group['outcomes'] as $outcome)
                                                                    <a
                                                                        href="{{ $outcome['node']['navigation_target']['url'] }}"
                                                                        data-process-highway-outcome="{{ $outcome['node']['key'] }}"
                                                                        class="block rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:shadow-sm"
                                                                    >
                                                                        <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                                            {{ $outcome['edge']['label'] ?: 'Can lead to' }}
                                                                        </span>
                                                                        <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
                                                                    </a>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            </section>
                        </div>
                    </article>
                @endforeach

                <section
                    x-cloak
                    x-show="visibleCount() === 0"
                    class="rounded-3xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm sm:p-8"
                >
                    <h2 class="text-lg font-semibold text-slate-950">No process matches these filters</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Clear one or more filters to widen the audience.</p>
                    <button
                        type="button"
                        x-on:click="clearFilters()"
                        class="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Clear filters
                    </button>
                </section>
            </div>
        @endif

        <div
            x-cloak
            x-show="ramp !== null"
            x-transition.opacity
            x-on:click.self="closeRamp()"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4"
            role="presentation"
        >
            <section
                x-show="ramp !== null"
                class="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-3xl bg-white shadow-2xl ring-1 ring-slate-950/10"
                role="dialog"
                aria-modal="true"
                aria-label="Entry ramp details"
            >
                <template x-if="ramp !== null">
                    <div>
                        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500" x-text="ramp.criterion_label"></p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950" x-text="ramp.value_label"></h2>
                            </div>
                            <button
                                type="button"
                                x-on:click="closeRamp()"
                                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-950"
                                aria-label="Close"
                            >
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                                </svg>
                            </button>
                        </header>

                        <div class="space-y-5 px-5 py-5 sm:px-6">
                            <div class="rounded-2xl bg-slate-950 px-4 py-4 text-white">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-300">Contacts currently matching</p>
                                <p class="mt-1 text-3xl font-semibold" x-text="Number(ramp.contact_count).toLocaleString()"></p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-slate-950">How this can be applied</h3>
                                <div class="mt-3 space-y-2">
                                    <template x-for="source in ramp.application_sources" :key="source.key">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-950" x-text="source.label"></p>
                                                    <p class="mt-1 text-xs leading-5 text-slate-600" x-text="source.detail"></p>
                                                </div>
                                                <template x-if="source.url">
                                                    <a
                                                        x-bind:href="source.url"
                                                        class="shrink-0 text-xs font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                                                    >
                                                        Open
                                                    </a>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <p class="text-xs leading-5 text-slate-500">
                                The total reflects current contact facts. The listed paths show configured ways the fact can be assigned, not a historical attribution breakdown.
                            </p>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </div>
</x-layouts.crm>