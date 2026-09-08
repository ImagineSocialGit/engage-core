@props([
    'criterion',
    'name',
    'selectedValues' => [],
    'idPrefix' => 'webinar_filter',
])

<div
    class="space-y-4 rounded-xl border border-slate-200 bg-white p-4"
    x-data="{
        initialValues: @js(array_values(array_map('strval', $selectedValues))),
        seriesOptions: @js(data_get($criterion, 'presentation.audience_builder.series', [])),
        sessions: @js(data_get($criterion, 'presentation.audience_builder.sessions', [])),
        outcome: 'any',
        series: '',
        sessionScope: 'all',
        sessionIds: [],
        anchorSessionId: '',
        legacyValues: [],
        seriesOpen: false,
        seriesSearch: '',
        sessionOpen: false,
        sessionSearch: '',
        anchorOpen: false,
        anchorSearch: '',
        init() {
            this.restore(this.initialValues);
        },
        reset() {
            this.outcome = 'any';
            this.series = '';
            this.sessionScope = 'all';
            this.sessionIds = [];
            this.anchorSessionId = '';
            this.legacyValues = [];
            this.seriesOpen = false;
            this.seriesSearch = '';
            this.sessionOpen = false;
            this.sessionSearch = '';
            this.anchorOpen = false;
            this.anchorSearch = '';
        },
        restore(values) {
            const normalized = (values ?? []).map((value) => String(value).trim().toLowerCase()).filter(Boolean);

            if (normalized.length === 0) {
                return;
            }

            const parsed = normalized.map((value) => {
                const parts = value.split(':');
                const outcome = parts[parts.length - 1];

                if (! ['attended', 'missed'].includes(outcome)) {
                    return null;
                }

                if (parts.length === 2 && parts[0] === 'any') {
                    return { kind: 'any', identity: '', outcome, raw: value };
                }

                if (parts.length === 3 && ['series', 'session', 'before_session', 'on_or_after_session'].includes(parts[0])) {
                    return { kind: parts[0], identity: parts[1], outcome, raw: value };
                }

                return null;
            });

            if (parsed.some((item) => item === null)) {
                this.legacyValues = normalized;
                return;
            }

            const items = parsed.filter(Boolean);
            const kinds = [...new Set(items.map((item) => item.kind))];
            const outcomes = [...new Set(items.map((item) => item.outcome))];

            this.outcome = outcomes.length === 2 ? 'any' : outcomes[0];

            if (kinds.length !== 1) {
                this.legacyValues = normalized;
                return;
            }

            const kind = kinds[0];

            if (kind === 'any') {
                if ([...new Set(items.map((item) => item.identity))].length > 1) {
                    this.legacyValues = normalized;
                    return;
                }

                this.series = '';
                this.sessionScope = 'all';
                return;
            }

            if (kind === 'series') {
                const identities = [...new Set(items.map((item) => item.identity))];

                if (identities.length !== 1) {
                    this.legacyValues = normalized;
                    return;
                }

                this.series = identities[0];
                this.sessionScope = 'all';
                return;
            }

            const sessionIdentities = [...new Set(items.map((item) => item.identity))];
            const sessionRows = sessionIdentities
                .map((id) => this.sessionById(id))
                .filter(Boolean);
            const seriesValues = [...new Set(sessionRows.map((session) => String(session.series)))];

            if (sessionRows.length !== sessionIdentities.length || seriesValues.length !== 1) {
                this.legacyValues = normalized;
                return;
            }

            this.series = seriesValues[0];

            if (kind === 'session') {
                this.sessionScope = 'selected';
                this.sessionIds = sessionIdentities;
                return;
            }

            if (sessionIdentities.length !== 1) {
                this.legacyValues = normalized;
                return;
            }

            this.sessionScope = kind === 'before_session' ? 'before' : 'on_or_after';
            this.anchorSessionId = sessionIdentities[0];
        },
        beginEditing() {
            this.legacyValues = [];
        },
        setOutcome(value) {
            this.beginEditing();
            this.outcome = value;
        },
        selectSeries(value) {
            this.beginEditing();
            this.series = String(value ?? '');
            this.seriesOpen = false;
            this.seriesSearch = '';
            this.sessionIds = [];
            this.anchorSessionId = '';
            this.sessionScope = this.series === '' ? 'all' : 'all';
        },
        setSessionScope(value) {
            this.beginEditing();
            this.sessionScope = value;
            this.sessionIds = [];
            this.anchorSessionId = '';
            this.sessionOpen = false;
            this.anchorOpen = false;
        },
        toggleSession(id) {
            this.beginEditing();
            id = String(id);

            if (this.sessionIds.includes(id)) {
                this.sessionIds = this.sessionIds.filter((value) => value !== id);
                return;
            }

            this.sessionIds.push(id);
        },
        selectAnchor(id) {
            this.beginEditing();
            this.anchorSessionId = String(id);
            this.anchorOpen = false;
            this.anchorSearch = '';
        },
        sessionById(id) {
            id = String(id);
            return this.sessions.find((session) => String(session.id) === id) ?? null;
        },
        get filteredSeries() {
            const needle = this.seriesSearch.trim().toLowerCase();

            if (needle === '') {
                return this.seriesOptions;
            }

            return this.seriesOptions.filter((option) => String(option.label ?? '').toLowerCase().includes(needle));
        },
        get availableSessions() {
            if (this.series === '') {
                return [];
            }

            return this.sessions.filter((session) => String(session.series) === String(this.series));
        },
        get filteredSessions() {
            const needle = this.sessionSearch.trim().toLowerCase();

            if (needle === '') {
                return this.availableSessions;
            }

            return this.availableSessions.filter((session) => String(session.search_label ?? session.label ?? '').toLowerCase().includes(needle));
        },
        get filteredAnchors() {
            const needle = this.anchorSearch.trim().toLowerCase();

            if (needle === '') {
                return this.availableSessions;
            }

            return this.availableSessions.filter((session) => String(session.search_label ?? session.label ?? '').toLowerCase().includes(needle));
        },
        get selectedSeriesLabel() {
            if (this.series === '') {
                return 'All webinar types';
            }

            return this.seriesOptions.find((option) => String(option.value) === String(this.series))?.label ?? this.series;
        },
        get selectedSessionRows() {
            return this.availableSessions.filter((session) => this.sessionIds.includes(String(session.id)));
        },
        get anchorLabel() {
            return this.sessionById(this.anchorSessionId)?.label ?? 'Choose a session…';
        },
        get encodedValues() {
            if (this.legacyValues.length > 0) {
                return this.legacyValues;
            }

            const outcomes = this.outcome === 'any'
                ? ['attended', 'missed']
                : [this.outcome];
            let prefixes = [];

            if (this.series === '') {
                prefixes = ['any'];
            } else if (this.sessionScope === 'all') {
                prefixes = [`series:${this.series}`];
            } else if (this.sessionScope === 'selected') {
                prefixes = this.sessionIds.map((id) => `session:${id}`);
            } else if (this.sessionScope === 'before' && this.anchorSessionId !== '') {
                prefixes = [`before_session:${this.anchorSessionId}`];
            } else if (this.sessionScope === 'on_or_after' && this.anchorSessionId !== '') {
                prefixes = [`on_or_after_session:${this.anchorSessionId}`];
            }

            return prefixes.flatMap((prefix) => outcomes.map((outcome) => `${prefix}:${outcome}`));
        },
    }"
    x-on:audience-clear-filters.window="reset()"
>
    <template x-for="value in encodedValues" :key="`{{ $idPrefix }}-${value}`">
        <input type="hidden" name="{{ $name }}[]" x-bind:value="value">
    </template>

    <template x-if="legacyValues.length > 0">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <div>This saved Webinar filter combines older options that cannot be represented as one hierarchy. It will be preserved unless you rebuild it.</div>
            <button type="button" class="mt-2 font-semibold underline" x-on:click="reset()">Rebuild Webinar filter</button>
        </div>
    </template>

    <div class="grid gap-4 lg:grid-cols-2">
        <div>
            <x-ui.form.label for="{{ $idPrefix }}_outcome">Outcome</x-ui.form.label>
            <select
                id="{{ $idPrefix }}_outcome"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                x-bind:value="outcome"
                x-on:change="setOutcome($event.target.value)"
            >
                <option value="any">Any outcome</option>
                <option value="attended">Attended</option>
                <option value="missed">Missed</option>
            </select>
        </div>

        <div class="relative" x-on:click.outside="seriesOpen = false">
            <x-ui.form.label>Webinar type</x-ui.form.label>
            <button
                type="button"
                class="mt-1 flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                x-on:click="seriesOpen = ! seriesOpen"
            >
                <span class="truncate" x-text="selectedSeriesLabel"></span>
                <span class="shrink-0 text-slate-400">⌄</span>
            </button>

            <div
                x-cloak
                x-show="seriesOpen"
                x-transition.opacity
                class="absolute z-40 mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 shadow-xl"
            >
                <input
                    type="search"
                    x-model.debounce.100ms="seriesSearch"
                    placeholder="Type to find a webinar type…"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                >

                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-100">
                    <button
                        type="button"
                        class="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm hover:bg-slate-50"
                        x-on:click="selectSeries('')"
                    >
                        All webinar types
                    </button>

                    <template x-for="option in filteredSeries" :key="option.value">
                        <button
                            type="button"
                            class="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-slate-50"
                            x-on:click="selectSeries(option.value)"
                            x-text="option.label"
                        ></button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <div x-show="series !== ''" x-cloak class="space-y-3">
        <div>
            <x-ui.form.label for="{{ $idPrefix }}_session_scope">Sessions</x-ui.form.label>
            <select
                id="{{ $idPrefix }}_session_scope"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                x-bind:value="sessionScope"
                x-on:change="setSessionScope($event.target.value)"
            >
                <option value="all">All sessions in this webinar type</option>
                <option value="selected">Specific sessions</option>
                <option value="before">Sessions before a selected session</option>
                <option value="on_or_after">Selected session and later</option>
            </select>
        </div>

        <div x-show="sessionScope === 'selected'" x-cloak class="relative" x-on:click.outside="sessionOpen = false">
            <button
                type="button"
                class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                x-on:click="sessionOpen = ! sessionOpen"
            >
                <span x-text="sessionIds.length > 0 ? `${sessionIds.length} sessions selected` : 'Choose sessions…'"></span>
                <span class="shrink-0 text-slate-400">⌄</span>
            </button>

            <div
                x-cloak
                x-show="sessionOpen"
                x-transition.opacity
                class="absolute z-40 mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 shadow-xl"
            >
                <input
                    type="search"
                    x-model.debounce.100ms="sessionSearch"
                    placeholder="Type a date or time…"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                >

                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-100">
                    <template x-for="session in filteredSessions" :key="session.id">
                        <label class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-3 py-2 last:border-b-0 hover:bg-slate-50">
                            <input
                                type="checkbox"
                                class="mt-0.5 rounded border-slate-300"
                                x-bind:checked="sessionIds.includes(String(session.id))"
                                x-on:change="toggleSession(session.id)"
                            >
                            <span class="text-sm text-slate-700" x-text="session.label"></span>
                        </label>
                    </template>

                    <div x-show="filteredSessions.length === 0" class="px-3 py-4 text-center text-xs text-slate-500">
                        No matching sessions.
                    </div>
                </div>
            </div>

            <div class="mt-2 flex flex-wrap gap-2" x-show="selectedSessionRows.length > 0">
                <template x-for="session in selectedSessionRows" :key="`selected-session-${session.id}`">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                        x-on:click="toggleSession(session.id)"
                    >
                        <span x-text="session.label"></span>
                        <span aria-hidden="true">×</span>
                    </button>
                </template>
            </div>
        </div>

        <div x-show="sessionScope === 'before' || sessionScope === 'on_or_after'" x-cloak class="relative" x-on:click.outside="anchorOpen = false">
            <button
                type="button"
                class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                x-on:click="anchorOpen = ! anchorOpen"
            >
                <span class="truncate" x-text="anchorLabel"></span>
                <span class="shrink-0 text-slate-400">⌄</span>
            </button>

            <div
                x-cloak
                x-show="anchorOpen"
                x-transition.opacity
                class="absolute z-40 mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 shadow-xl"
            >
                <input
                    type="search"
                    x-model.debounce.100ms="anchorSearch"
                    placeholder="Type a date or time…"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                >

                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-100">
                    <template x-for="session in filteredAnchors" :key="session.id">
                        <button
                            type="button"
                            class="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-slate-50"
                            x-on:click="selectAnchor(session.id)"
                            x-text="session.label"
                        ></button>
                    </template>

                    <div x-show="filteredAnchors.length === 0" class="px-3 py-4 text-center text-xs text-slate-500">
                        No matching sessions.
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(filled($criterion['help'] ?? null))
        <p class="text-xs text-slate-500">{{ $criterion['help'] }}</p>
    @endif
</div>