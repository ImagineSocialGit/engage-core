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
    $roleLabels = [
        'trigger' => 'Trigger',
        'qualifier' => 'Contact fact',
        'gateway' => 'Qualification',
        'process' => 'Mechanism',
        'action' => 'Action',
        'consequence' => 'Outcome',
        'exit' => 'Exit',
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
            matches(item) {
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
            hasActiveFilters() {
                return this.lane !== ''
                    || this.query.trim() !== ''
                    || Object.values(this.qualifiers).some((value) => value !== '');
            },
            clearFilters() {
                this.lane = '';
                this.query = '';
                Object.keys(this.qualifiers).forEach((key) => this.qualifiers[key] = '');
            },
        }"
    >
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="p-5 sm:p-8">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">
                            Business process map
                        </p>

                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            Follow the road from contact facts to outcomes
                        </h2>

                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            Each highway connects the facts that bring someone in, what happens automatically, and where the process can lead. Open any item to edit it in the feature that owns it.
                        </p>
                    </div>

                    @if(($highway['highway_count'] ?? 0) > 0)
                        <dl class="grid shrink-0 grid-cols-3 gap-2">
                            <div class="min-w-24 rounded-2xl bg-slate-50 px-3 py-3 text-center ring-1 ring-slate-200">
                                <dd class="text-xl font-semibold text-slate-950">{{ $highway['highway_count'] }}</dd>
                                <dt class="text-xs font-medium text-slate-500">highways</dt>
                            </div>
                            <div class="min-w-24 rounded-2xl bg-slate-50 px-3 py-3 text-center ring-1 ring-slate-200">
                                <dd class="text-xl font-semibold text-slate-950">{{ $highway['segment_count'] }}</dd>
                                <dt class="text-xs font-medium text-slate-500">mechanisms</dt>
                            </div>
                            <div class="min-w-24 rounded-2xl bg-slate-50 px-3 py-3 text-center ring-1 ring-slate-200">
                                <dd class="text-xl font-semibold text-slate-950">{{ $highway['source_count'] }}</dd>
                                <dt class="text-xs font-medium text-slate-500">features</dt>
                            </div>
                        </dl>
                    @endif
                </div>
            </div>
        </section>

        @if(($highway['highway_count'] ?? 0) === 0)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">No configured business highways yet</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    This surface stays available when optional process features are disabled. Highways appear automatically as enabled features contribute connected facts and actions.
                </p>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">Choose what to map</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Combine scope and contact facts to isolate the road you want to review.
                        </p>
                    </div>

                    <div class="flex items-center gap-3 text-sm text-slate-600">
                        <span><strong class="font-semibold text-slate-950" x-text="visibleCount()"></strong> shown</span>
                        <button
                            type="button"
                            x-cloak
                            x-show="hasActiveFilters()"
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
                            <option value="">All contact scopes</option>
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
                                <option value="">Any {{ strtolower($filter['label']) }}</option>
                                @foreach($filter['options'] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach

                    <label class="block md:col-span-2 xl:col-span-4">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Find a process</span>
                        <input
                            type="search"
                            x-model.debounce.200ms="query"
                            placeholder="Search entrances, actions, outcomes, or program names"
                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-500 focus:ring-slate-500"
                        >
                    </label>
                </div>
            </section>

            <div class="space-y-6" aria-live="polite">
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
                        <header class="border-b border-slate-200 bg-slate-50/70 px-5 py-5 sm:px-7">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-300">
                                            {{ $businessHighway['subject_label'] }}
                                        </span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-300">
                                            {{ $businessHighway['lane_label'] }}
                                        </span>
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $businessHighway['state'] === 'active',
                                            'bg-amber-50 text-amber-900 ring-amber-200' => $businessHighway['state'] === 'needs_configuration',
                                            'bg-slate-100 text-slate-600 ring-slate-200' => $businessHighway['state'] === 'off',
                                        ])>
                                            {{ $businessHighway['state_label'] }}
                                        </span>
                                    </div>

                                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-950">
                                        {{ $businessHighway['name'] }}
                                    </h2>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ $businessHighway['segment_count'] }} connected {{ \Illuminate\Support\Str::plural('mechanism', $businessHighway['segment_count']) }} across {{ $businessHighway['source_count'] }} {{ \Illuminate\Support\Str::plural('feature', $businessHighway['source_count']) }}.
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    @foreach($businessHighway['source_labels'] as $sourceLabel)
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                            {{ $sourceLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </header>

                        <div class="p-5 sm:p-7">
                            <div class="grid gap-5 xl:grid-cols-[minmax(12rem,0.75fr)_2.5rem_minmax(30rem,2fr)_2.5rem_minmax(12rem,0.8fr)] xl:items-stretch">
                                <section aria-label="Entry ramps">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Entry ramps</p>
                                    <div class="mt-3 space-y-3">
                                        @foreach($businessHighway['entry_nodes'] as $node)
                                            @php($target = $node['navigation_target'])
                                            <a
                                                href="{{ $target['url'] }}"
                                                class="block rounded-2xl p-4 ring-1 transition hover:-translate-y-0.5 hover:shadow-md {{ module_tone($node['authority']['owner_key'], 'item') }}"
                                                title="{{ $target['label'] }}"
                                            >
                                                <span class="rounded-full px-2 py-1 text-[0.68rem] font-bold ring-1 {{ module_tone($node['authority']['owner_key'], 'badge') }}">
                                                    {{ $node['authority']['owner_label'] }}
                                                </span>
                                                <span class="mt-3 block text-sm font-semibold leading-5 text-slate-950">{{ $node['label'] }}</span>
                                                @if($node['detail'])
                                                    <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $node['detail'] }}</span>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                </section>

                                <div class="flex items-center justify-center text-slate-400" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" class="h-7 w-7 xl:hidden" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0 6-6m-6 6-6-6" />
                                    </svg>
                                    <svg viewBox="0 0 24 24" class="hidden h-8 w-8 xl:block" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-6-6m6 6-6 6" />
                                    </svg>
                                </div>

                                <section aria-label="Configured mechanisms">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">What happens automatically</p>
                                    <div class="mt-3 grid gap-4 2xl:grid-cols-2">
                                        @foreach($businessHighway['segments'] as $segment)
                                            @php($segmentTarget = $segment['navigation_target'])
                                            <article class="flex min-w-0 flex-col rounded-2xl p-4 ring-1 sm:p-5 {{ module_tone($segment['source_key'], 'panel') }}">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div class="min-w-0">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ module_tone($segment['source_key'], 'badge') }}">
                                                                {{ $segment['authority']['owner_label'] }}
                                                            </span>
                                                            <span @class([
                                                                'rounded-full px-2.5 py-1 text-xs font-bold',
                                                                'bg-emerald-100 text-emerald-800' => $segment['state'] === 'active',
                                                                'bg-amber-100 text-amber-900' => $segment['state'] === 'needs_configuration',
                                                                'bg-white/80 text-slate-600' => ! in_array($segment['state'], ['active', 'needs_configuration'], true),
                                                            ])>
                                                                {{ $segment['state_label'] }}
                                                            </span>
                                                        </div>

                                                        <h3 class="mt-3 text-base font-semibold leading-6 text-slate-950">{{ $segment['name'] }}</h3>
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

                                                @if($segment['entry_summary'])
                                                    <div class="mt-4 rounded-xl bg-white/75 px-3 py-3 text-sm leading-5 text-slate-700 ring-1 ring-black/5">
                                                        <span class="font-semibold text-slate-950">Starts when:</span>
                                                        {{ $segment['entry_summary'] }}
                                                    </div>
                                                @endif

                                                <ol class="mt-4 space-y-0">
                                                    @forelse($segment['journey_nodes'] as $node)
                                                        @php($target = $node['navigation_target'])
                                                        <li class="relative flex gap-3 pb-4 last:pb-0">
                                                            @if(! $loop->last)
                                                                <span class="absolute bottom-0 left-[0.6875rem] top-6 w-px bg-slate-300" aria-hidden="true"></span>
                                                            @endif
                                                            <span class="relative mt-1 h-5 w-5 shrink-0 rounded-full border-4 border-white bg-slate-400 ring-1 ring-slate-300" aria-hidden="true"></span>
                                                            <a
                                                                href="{{ $target['url'] }}"
                                                                class="min-w-0 flex-1 rounded-xl px-3 py-3 ring-1 transition hover:shadow-sm {{ module_tone($node['authority']['owner_key'], 'item') }}"
                                                                title="{{ $target['label'] }}"
                                                            >
                                                                <span class="flex flex-wrap items-center gap-2">
                                                                    <span class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                                        {{ $roleLabels[$node['role']] ?? \Illuminate\Support\Str::headline($node['role']) }}
                                                                    </span>
                                                                    <span class="rounded-full px-2 py-0.5 text-[0.65rem] font-bold ring-1 {{ module_tone($node['authority']['owner_key'], 'badge') }}">
                                                                        {{ $node['authority']['owner_label'] }}
                                                                    </span>
                                                                </span>
                                                                <span class="mt-1 block text-sm font-semibold leading-5 text-slate-950">{{ $node['label'] }}</span>
                                                                @if($node['detail'])
                                                                    <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $node['detail'] }}</span>
                                                                @endif
                                                            </a>
                                                        </li>
                                                    @empty
                                                        <li class="rounded-xl bg-white/75 px-3 py-3 text-sm text-slate-600 ring-1 ring-black/5">
                                                            This mechanism handles the transition directly.
                                                        </li>
                                                    @endforelse
                                                </ol>

                                                @if($segment['branch_edges'] !== [])
                                                    <div class="mt-4 border-t border-black/5 pt-4">
                                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Decision paths</p>
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            @foreach($segment['branch_edges'] as $edge)
                                                                @php($target = $edge['navigation_target'])
                                                                <a
                                                                    href="{{ $target['url'] }}"
                                                                    class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
                                                                    title="{{ $target['label'] }}"
                                                                >
                                                                    {{ $edge['label'] ?: 'Branch' }}
                                                                    @if($edge['to_label'])
                                                                        <span class="text-slate-400">→</span> {{ $edge['to_label'] }}
                                                                    @endif
                                                                </a>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                @if($segment['details'] !== [])
                                                    <details class="mt-auto pt-4">
                                                        <summary class="cursor-pointer text-sm font-semibold text-slate-700 marker:text-slate-400">
                                                            How this is implemented
                                                        </summary>
                                                        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                                            @foreach($segment['details'] as $detail)
                                                                <div class="rounded-xl bg-white/75 px-3 py-2 ring-1 ring-black/5">
                                                                    <dt class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-slate-500">{{ $detail['label'] }}</dt>
                                                                    <dd class="mt-1 text-xs font-semibold leading-5 text-slate-800">{{ $detail['value'] }}</dd>
                                                                </div>
                                                            @endforeach
                                                        </dl>
                                                    </details>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                </section>

                                <div class="flex items-center justify-center text-slate-400" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" class="h-7 w-7 xl:hidden" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0 6-6m-6 6-6-6" />
                                    </svg>
                                    <svg viewBox="0 0 24 24" class="hidden h-8 w-8 xl:block" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-6-6m6 6-6 6" />
                                    </svg>
                                </div>

                                <section aria-label="Outcomes and exits">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Outcomes and exits</p>
                                    <div class="mt-3 space-y-3">
                                        @forelse($businessHighway['exit_nodes'] as $node)
                                            @php($target = $node['navigation_target'])
                                            <a
                                                href="{{ $target['url'] }}"
                                                class="block rounded-2xl p-4 ring-1 transition hover:-translate-y-0.5 hover:shadow-md {{ module_tone($node['authority']['owner_key'], 'item') }}"
                                                title="{{ $target['label'] }}"
                                            >
                                                <span class="rounded-full px-2 py-1 text-[0.68rem] font-bold ring-1 {{ module_tone($node['authority']['owner_key'], 'badge') }}">
                                                    {{ $node['authority']['owner_label'] }}
                                                </span>
                                                <span class="mt-3 block text-sm font-semibold leading-5 text-slate-950">{{ $node['label'] }}</span>
                                                @if($node['detail'])
                                                    <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $node['detail'] }}</span>
                                                @endif
                                            </a>
                                        @empty
                                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm leading-5 text-slate-600">
                                                The configured road continues into another connected mechanism.
                                            </div>
                                        @endforelse
                                    </div>
                                </section>
                            </div>
                        </div>
                    </article>
                @endforeach

                <section
                    x-cloak
                    x-show="visibleCount() === 0"
                    class="rounded-3xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm sm:p-8"
                >
                    <h2 class="text-lg font-semibold text-slate-950">No highway matches these filters</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Clear one or more filters to widen the map.</p>
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
    </div>
</x-layouts.crm>