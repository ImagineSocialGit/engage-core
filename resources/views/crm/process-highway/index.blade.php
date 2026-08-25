@php
    $subjects = collect($highway['subjects'] ?? []);
    $highways = collect($highway['highways'] ?? []);
    $qualifierFilters = collect($highway['qualifier_filters'] ?? []);
    $primaryQualifierKeys = ['status', 'tag', 'webinar_outcome'];
    $primaryQualifierFilters = $qualifierFilters
        ->filter(fn (array $filter): bool => in_array($filter['key'], $primaryQualifierKeys, true))
        ->values();
    $secondaryQualifierFilters = $qualifierFilters
        ->reject(fn (array $filter): bool => in_array($filter['key'], $primaryQualifierKeys, true))
        ->values();
    $initialSubject = (string) (($subjects->first()['key'] ?? null) ?: 'contacts');
    $laneOptions = $subjects
        ->flatMap(fn (array $subject): array => array_map(
            fn (array $lane): array => [...$lane, 'subject_key' => $subject['key']],
            $subject['lanes'] ?? [],
        ))
        ->values();
    $relationshipOptions = $laneOptions
        ->filter(fn (array $lane): bool => ($lane['scope'] ?? null) === 'relationship')
        ->filter(fn (array $lane): bool => is_string($lane['relationship_key'] ?? null))
        ->map(fn (array $lane): array => [
            'value' => $lane['relationship_key'],
            'label' => $lane['relationship_label'] ?? $lane['label'],
        ])
        ->merge(
            collect($qualifierFilters->firstWhere('key', 'relationship')['options'] ?? [])
                ->map(function (array $option): array {
                    $relationshipKey = explode(':', (string) $option['value'], 2)[0];

                    return [
                        'value' => $relationshipKey,
                        'label' => str($relationshipKey)->headline()->toString(),
                    ];
                }),
        )
        ->unique('value')
        ->sortBy('label')
        ->values();
    $qualifierSelection = $qualifierFilters
        ->mapWithKeys(fn (array $filter): array => [$filter['key'] => ''])
        ->all();
    $qualifierLabels = $qualifierFilters
        ->mapWithKeys(fn (array $filter): array => [
            $filter['key'] => [
                'label' => $filter['label'],
                'options' => collect($filter['options'])
                    ->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])
                    ->all(),
            ],
        ])
        ->all();
    $filterableHighways = $highways
        ->map(fn (array $item): array => [
            'key' => $item['key'],
            'subject_key' => $item['subject_key'],
            'lane_scope' => $item['lane_scope'],
            'relationship_key' => $item['relationship_key'],
            'qualifiers' => $item['qualifiers'],
            'entry_requirements' => $item['entry_requirements'],
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
        class="space-y-5"
        data-process-highway
        x-data="{
            items: @js($filterableHighways),
            subject: @js($initialSubject),
            contactMode: 'standard',
            relationship: '',
            query: '',
            qualifiers: @js($qualifierSelection),
            qualifierLabels: @js($qualifierLabels),
            expandedHighway: '',
            showMoreFilters: false,
            ramp: null,
            selectedCriteria() {
                return Object.entries(this.qualifiers)
                    .filter(([, value]) => value !== '')
                    .map(([key, value]) => ({ key, value }));
            },
            hasAudienceSelection() {
                return this.selectedCriteria().length > 0
                    || (this.contactMode === 'relationship' && this.relationship !== '');
            },
            scopeMatches(item) {
                if (this.contactMode === 'standard') {
                    return item.lane_scope === 'standard';
                }

                if (item.lane_scope !== 'relationship' || ! this.relationship) {
                    return false;
                }

                if (item.relationship_key === this.relationship) {
                    return true;
                }

                return (item.qualifiers.relationship || []).some((value) => {
                    return value === this.relationship || value.startsWith(`${this.relationship}:`);
                });
            },
            baseMatches(item) {
                if (! this.hasAudienceSelection() || item.subject_key !== this.subject || ! this.scopeMatches(item)) {
                    return false;
                }

                const query = this.query.trim().toLowerCase();

                return ! query || item.search_text.includes(query);
            },
            isExact(item) {
                if (! this.baseMatches(item)) {
                    return false;
                }

                const selected = this.selectedCriteria();
                const requirements = item.entry_requirements || [];

                if (selected.length === 0) {
                    return requirements.length === 0;
                }

                if (requirements.length !== selected.length) {
                    return false;
                }

                return selected.every((criterion) => {
                    const requirement = requirements.find((candidate) => candidate.criterion_key === criterion.key);

                    return requirement && requirement.values.some((candidate) => candidate.value === criterion.value);
                });
            },
            isPartial(item) {
                if (! this.baseMatches(item) || this.isExact(item)) {
                    return false;
                }

                const selected = this.selectedCriteria();

                if (selected.length === 0) {
                    return this.contactMode === 'relationship' && this.relationship !== '';
                }

                const requirements = item.entry_requirements || [];

                return selected.some((criterion) => {
                    const requirement = requirements.find((candidate) => candidate.criterion_key === criterion.key);

                    return requirement && requirement.values.some((candidate) => candidate.value === criterion.value);
                });
            },
            exactItems() {
                return this.items.filter((item) => this.isExact(item));
            },
            partialItems() {
                return this.items.filter((item) => this.isPartial(item));
            },
            exactCount() {
                return this.exactItems().length;
            },
            partialCount() {
                return this.partialItems().length;
            },
            visibleCount() {
                return this.exactCount() + this.partialCount();
            },
            audienceLabels() {
                const labels = [this.contactMode === 'standard'
                    ? 'Standard contacts'
                    : `${this.relationship || 'Relationship'} relationships`];

                this.selectedCriteria().forEach(({ key, value }) => {
                    const filter = this.qualifierLabels[key] || {};
                    const option = (filter.options || {})[value] || value;
                    labels.push(`${filter.label || key}: ${option}`);
                });

                return labels;
            },
            setContactMode(mode) {
                this.contactMode = mode;
                this.relationship = '';

                if (this.qualifiers.relationship !== undefined) {
                    this.qualifiers.relationship = '';
                }

                this.filterChanged();
            },
            filterChanged() {
                this.$nextTick(() => {
                    const visible = [...this.exactItems(), ...this.partialItems()];

                    if (! visible.some((item) => item.key === this.expandedHighway)) {
                        this.expandedHighway = visible[0]?.key || '';
                    }
                });
            },
            clearFilters() {
                this.contactMode = 'standard';
                this.relationship = '';
                this.query = '';
                this.expandedHighway = '';
                Object.keys(this.qualifiers).forEach((key) => this.qualifiers[key] = '');
            },
            toggleHighway(key) {
                this.expandedHighway = this.expandedHighway === key ? '' : key;
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
        @if(($highway['highway_count'] ?? 0) === 0)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">No configured business processes yet</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Processes appear automatically as enabled features contribute contact entrances and mechanisms.
                </p>
            </section>
        @else
            <section data-process-highway-audience class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">1 · Choose an audience</p>
                        <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">What happens automatically to these contacts?</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Choose at least one contact fact. Exact processes appear first; processes that use your selection as only part of their entrance appear separately.
                        </p>
                    </div>

                    <button
                        type="button"
                        x-cloak
                        x-show="hasAudienceSelection() || query.trim() !== ''"
                        x-on:click="clearFilters()"
                        class="shrink-0 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 transition hover:text-slate-950"
                    >
                        Start over
                    </button>
                </div>

                <fieldset class="mt-6" data-process-highway-contact-mode>
                    <legend class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Contacts to view</legend>
                    <div class="mt-2 inline-flex rounded-xl bg-slate-100 p-1">
                        <button type="button" x-on:click="setContactMode('standard')" x-bind:class="contactMode === 'standard' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600'" class="min-h-10 rounded-lg px-4 py-2 text-sm font-semibold transition">
                            Standard contacts
                        </button>
                        <button type="button" x-on:click="setContactMode('relationship')" x-bind:class="contactMode === 'relationship' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600'" class="min-h-10 rounded-lg px-4 py-2 text-sm font-semibold transition">
                            Contacts with relationships
                        </button>
                    </div>
                </fieldset>

                <div data-process-highway-primary-filters class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label x-cloak x-show="contactMode === 'relationship'" class="block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Relationship type</span>
                        <select x-model="relationship" x-on:change="filterChanged()" data-process-highway-relationship-type class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500">
                            <option value="">Choose a relationship</option>
                            @foreach($relationshipOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    @foreach($primaryQualifierFilters as $filter)
                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">{{ $filter['label'] }}</span>
                            <select x-model="qualifiers['{{ $filter['key'] }}']" x-on:change="filterChanged()" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                <option value="">Any {{ strtolower($filter['label']) }}</option>
                                @foreach($filter['options'] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>

                @if($subjects->count() > 1 || $secondaryQualifierFilters->isNotEmpty())
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        <button type="button" x-on:click="showMoreFilters = ! showMoreFilters" x-bind:aria-expanded="showMoreFilters" data-process-highway-more-filters-toggle class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 transition hover:text-slate-950">
                            <span x-text="showMoreFilters ? 'Hide additional filters' : 'More ways to narrow'">More ways to narrow</span>
                        </button>

                        <div x-cloak x-show="showMoreFilters" data-process-highway-secondary-filters class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            @if($subjects->count() > 1)
                                <label class="block">
                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">Subject</span>
                                    <select x-model="subject" x-on:change="filterChanged()" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                        @foreach($subjects as $subject)
                                            <option value="{{ $subject['key'] }}">{{ $subject['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif

                            @foreach($secondaryQualifierFilters as $filter)
                                <label @if($filter['key'] === 'relationship') x-cloak x-show="contactMode === 'relationship'" @endif class="block">
                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-600">{{ $filter['key'] === 'relationship' ? 'Relationship stage' : $filter['label'] }}</span>
                                    <select x-model="qualifiers['{{ $filter['key'] }}']" x-on:change="filterChanged()" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900 shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                        <option value="">Any {{ strtolower($filter['label']) }}</option>
                                        @foreach($filter['options'] as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section x-show="! hasAudienceSelection()" data-process-highway-awaiting-filter class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-slate-950">Choose at least one contact fact to begin</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">For example, select Past Client, Old Lead, or a webinar outcome.</p>
            </section>

            <section x-cloak x-show="hasAudienceSelection()" data-process-highway-results aria-live="polite" class="space-y-5">
                <div class="rounded-3xl border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-7">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">2 · Review what applies</p>
                            <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                                    <span x-text="visibleCount()"></span>
                                    <span x-text="visibleCount() === 1 ? 'relevant process' : 'relevant processes'"></span>
                                </h2>
                                <span class="text-sm text-slate-500" x-text="audienceLabels().join(' · ')"></span>
                            </div>
                        </div>

                        <label class="block w-full lg:max-w-sm">
                            <span class="sr-only">Search relevant processes</span>
                            <input type="search" x-model.debounce.200ms="query" x-on:input.debounce.200ms="filterChanged()" placeholder="Search these processes" class="block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-500 focus:ring-slate-500">
                        </label>
                    </div>
                </div>

                <section x-show="exactCount() > 0" data-process-highway-exact-results class="space-y-4">
                    <div class="px-1">
                        <h2 class="text-base font-semibold text-slate-950">Exact entrance match</h2>
                        <p class="mt-1 text-sm text-slate-600">Every entrance requirement is satisfied by the audience you selected.</p>
                    </div>
                    @foreach($highways as $businessHighway)
                        @include('crm.process-highway._business-process', ['businessHighway' => $businessHighway, 'matchMethod' => 'isExact', 'matchKind' => 'exact'])
                    @endforeach
                </section>

                <section x-show="exactCount() === 0" data-process-highway-no-exact-match class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-6">
                    <h2 class="text-lg font-semibold text-amber-950">No process starts from this exact selection</h2>
                    <p class="mt-2 text-sm leading-6 text-amber-900/80">Processes shown below use your selection as only part of their entrance. Review the remaining requirements before assuming they apply.</p>
                </section>

                <section x-show="partialCount() > 0" data-process-highway-partial-results class="space-y-4">
                    <div class="px-1">
                        <h2 class="text-base font-semibold text-slate-950">Partial entrance matches</h2>
                        <p class="mt-1 text-sm text-slate-600">Your selection is one requirement, but these processes need additional facts.</p>
                    </div>
                    @foreach($highways as $businessHighway)
                        @include('crm.process-highway._business-process', ['businessHighway' => $businessHighway, 'matchMethod' => 'isPartial', 'matchKind' => 'partial'])
                    @endforeach
                </section>

                <section x-show="visibleCount() === 0" class="rounded-3xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-slate-950">No process uses this audience selection</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Change one filter or start over to review another audience.</p>
                </section>
            </section>
        @endif

        <div x-cloak x-show="ramp !== null" x-transition.opacity x-on:click.self="closeRamp()" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" role="presentation">
            <section x-show="ramp !== null" class="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-3xl bg-white shadow-2xl ring-1 ring-slate-950/10" role="dialog" aria-modal="true" aria-label="Entry ramp details">
                <template x-if="ramp !== null">
                    <div>
                        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500" x-text="ramp.criterion_label"></p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950" x-text="ramp.value_label"></h2>
                            </div>
                            <button type="button" x-on:click="closeRamp()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-950" aria-label="Close">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" /></svg>
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
                                                <div><p class="text-sm font-semibold text-slate-950" x-text="source.label"></p><p class="mt-1 text-xs leading-5 text-slate-600" x-text="source.detail"></p></div>
                                                <template x-if="source.url"><a x-bind:href="source.url" class="shrink-0 text-xs font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Open</a></template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <p class="text-xs leading-5 text-slate-500">The total reflects current contact facts. The listed paths show configured ways the fact can be assigned, not a historical attribution breakdown.</p>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </div>
</x-layouts.crm>