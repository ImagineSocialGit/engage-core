<x-layouts.crm :title="$title" :heading="$heading">
    <div
        class="space-y-6"
        x-data="{
            activeDevTestingModal: null,
            openDevTestingModal(name) {
                this.activeDevTestingModal = name;
            }
        }"
    >
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if (session('zoom_sync_error'))
            <div
                x-data="{ open: true }"
                x-show="open"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            >
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-gray-950">
                        Zoom Sync Failed
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-gray-700">
                        {{ session('zoom_sync_error') }}
                    </p>

                    <div class="mt-6 flex justify-end">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if(session('sync_conflicts') && count(session('sync_conflicts')))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Active webinar conflicts detected.</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_conflicts') as $conflict)
                        <li class="flex items-center justify-between gap-4">
                            <span>
                                {{ $conflict['series'] }} — active: {{ $conflict['active'] }}, expected: {{ $conflict['expected'] }}
                            </span>

                            <form method="POST" action="{{ route('crm.webinar-series.fix-active', $conflict['webinar_series_id']) }}">
                                @csrf

                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500"
                                >
                                    Fix
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('sync_missing') && count(session('sync_missing')))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">Missing webinars preserved (not deleted).</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_missing') as $item)
                        <li>
                            {{ $item['title'] }}
                            @if($item['has_registrations'])
                                — has registrations
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($webinarDevEnabled ?? $webinarSmokeEnabled ?? false)
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                <p class="font-semibold">Webinar Testing Tools Enabled</p>
                <p class="mt-1 text-indigo-800">
                    Use the Testing button on any webinar to open dev/staging-only controls for confirmations, reminders, join simulation, FlowRoute events, replay URLs, and post-webinar follow-ups.
                </p>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between px-12 py-2">
                    <h2 class="text-sm font-semibold text-slate-900">
                        {{ $showArchived ? 'All Webinars' : 'Upcoming Webinars' }}
                    </h2>

                    <a
                        href="{{ $showArchived ? route('crm.webinar-series.index') : route('crm.webinar-series.index', ['archived' => 1]) }}"
                        class="text-sm font-medium text-slate-600 hover:text-slate-900 underline"
                    >
                        {{ $showArchived ? 'View Upcoming' : 'View Archived' }}
                    </a>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Title</th>
                            <th class="px-6 py-3">Series</th>
                            <th class="px-6 py-3">Start</th>
                            <th class="px-6 py-3">Timezone</th>
                            <th class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">
                        @forelse($webinars as $webinar)
                            @php
                                $registrationUrl = $webinar->webinarSeries?->slug
                                    ? rtrim(config('app.webinar_url') ?: config('app.url'), '/') . route('webinar.show', $webinar->webinarSeries->slug, false)
                                    : null;

                                $modalName = 'webinar-dev-testing-'.$webinar->id;
                            @endphp

                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-medium text-slate-900">
                                    {{ $webinar->title }}

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        @if($webinar->playback_url)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                Replay set
                                            </span>
                                        @endif

                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">
                                            {{ $webinar->registrations->count() }} registrations
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $webinar->webinarSeries?->title ?? '—' }}
                                </td>

                                <td class="px-6 py-4 text-slate-700">
                                    {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $webinar->timezone }}
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                        @if($registrationUrl)
                                            <div
                                                x-data="{ copied: false }"
                                                class="inline-flex items-center gap-2"
                                            >
                                                <a
                                                    href="{{ $registrationUrl }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="text-xs font-semibold text-slate-600 underline hover:text-slate-900"
                                                >
                                                    View
                                                </a>

                                                <button
                                                    type="button"
                                                    x-on:click="
                                                        const text = @js($registrationUrl);

                                                        if (navigator.clipboard && window.isSecureContext) {
                                                            await navigator.clipboard.writeText(text);
                                                        } else {
                                                            const textarea = document.createElement('textarea');
                                                            textarea.value = text;
                                                            textarea.style.position = 'fixed';
                                                            textarea.style.opacity = '0';
                                                            document.body.appendChild(textarea);
                                                            textarea.focus();
                                                            textarea.select();
                                                            document.execCommand('copy');
                                                            textarea.remove();
                                                        }

                                                        copied = true;
                                                        setTimeout(() => copied = false, 1500);
                                                    "
                                                    class="inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700"
                                                >
                                                    <span x-show="!copied">Copy Link</span>
                                                    <span x-show="copied">Copied</span>
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400">No link</span>
                                        @endif

                                        @if($webinarDevEnabled ?? $webinarSmokeEnabled ?? false)
                                            <button
                                                type="button"
                                                x-on:click="openDevTestingModal(@js($modalName))"
                                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                                            >
                                                Testing
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            @if($webinarDevEnabled ?? $webinarSmokeEnabled ?? false)
                                <x-crm.dev-testing-modal
                                    :name="$modalName"
                                    title="Webinar Testing: {{ $webinar->title }}"
                                    subtitle="Send confirmations/reminders now, simulate join clicks, emit attendance events, and dispatch replay follow-ups without changing production timing config."
                                >
                                    <div
                                        x-data="{
                                            log: [],
                                            busyAction: null,
                                            csrfToken: @js(csrf_token()),
                                            record(type, message) {
                                                const now = new Date();
                                                this.log.unshift({
                                                    type,
                                                    message,
                                                    time: now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
                                                });
                                            },
                                            async run(label, action, formData = null) {
                                                if (this.busyAction) return;

                                                this.busyAction = label;

                                                try {
                                                    const response = await fetch(action, {
                                                        method: 'POST',
                                                        headers: {
                                                            'Accept': 'application/json',
                                                            'X-Requested-With': 'XMLHttpRequest',
                                                            'X-CSRF-TOKEN': this.csrfToken,
                                                        },
                                                        body: formData || new FormData(),
                                                    });

                                                    let data = {};

                                                    try {
                                                        data = await response.json();
                                                    } catch (error) {
                                                        data = {};
                                                    }

                                                    const message = data.message || (response.ok ? `${label} completed.` : `${label} failed.`);

                                                    this.record(response.ok ? 'success' : 'error', message);
                                                } catch (error) {
                                                    this.record('error', error.message || `${label} failed.`);
                                                } finally {
                                                    this.busyAction = null;
                                                }
                                            }
                                        }"
                                        class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
                                    >
                                        <div class="space-y-5">
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Series</div>
                                                        <div class="mt-1 font-semibold text-slate-900">{{ $webinar->webinarSeries?->title ?? '—' }}</div>
                                                    </div>

                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Start</div>
                                                        <div class="mt-1 font-semibold text-slate-900">
                                                            {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') ?? '—' }}
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Replay</div>
                                                        <div class="mt-1 font-semibold {{ $webinar->playback_url ? 'text-emerald-700' : 'text-slate-900' }}">
                                                            {{ $webinar->playback_url ? 'Set' : 'Not set' }}
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Registrations</div>
                                                        <div class="mt-1 font-semibold text-slate-900">{{ $webinar->registrations->count() }}</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="overflow-hidden rounded-2xl border border-slate-200">
                                                <div class="border-b border-slate-200 bg-white px-4 py-3">
                                                    <h3 class="text-sm font-bold text-slate-950">
                                                        Registration Testing
                                                    </h3>
                                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                                        Individual message sends load the active Messaging definitions for the registration’s accepted transactional channels, then force the selected definition to immediate delivery through the dev controller.
                                                    </p>
                                                </div>

                                                <div class="divide-y divide-slate-200 bg-white">
                                                    @forelse($webinar->registrations as $registration)
                                                        <div class="p-4">
                                                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                                                <div class="min-w-0">
                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                        @if($registration->contact)
                                                                            <a href="{{ route('crm.contacts.show', $registration->contact) }}" class="font-bold text-slate-950 underline hover:text-slate-700">
                                                                                {{ $registration->contact->name ?: trim(($registration->contact->first_name ?? '').' '.($registration->contact->last_name ?? '')) ?: 'Contact #'.$registration->contact->id }}
                                                                            </a>
                                                                        @else
                                                                            <span class="font-bold text-slate-950">No contact</span>
                                                                        @endif

                                                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                                                                            {{ $registration->status }}
                                                                        </span>

                                                                        @if($registration->attended_at)
                                                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                                                attended {{ $registration->attended_at->format('M j, g:i A') }}
                                                                            </span>
                                                                        @endif
                                                                    </div>

                                                                    <div class="mt-1 text-xs text-slate-500">
                                                                        {{ $registration->contact?->email ?? 'No email' }}
                                                                    </div>
                                                                </div>

                                                                <div class="grid gap-3 xl:min-w-[34rem]">
                                                                    <div class="flex flex-wrap justify-end gap-2">
                                                                        <form method="POST" action="{{ route('crm.webinar-registrations.dev.join.store', $registration) }}" x-on:submit.prevent="run('Sim Join', $el.action, new FormData($el))">
                                                                            @csrf
                                                                            <button type="submit" class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-blue-500">
                                                                                Sim Join
                                                                            </button>
                                                                        </form>

                                                                        <form method="POST" action="{{ route('crm.webinar-registrations.dev.attended.store', $registration) }}" x-on:submit.prevent="run('Mark Attended', $el.action, new FormData($el))">
                                                                            @csrf
                                                                            <button type="submit" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-500">
                                                                                Attended
                                                                            </button>
                                                                        </form>

                                                                        <form method="POST" action="{{ route('crm.webinar-registrations.dev.missed.store', $registration) }}" x-on:submit.prevent="run('Mark Missed', $el.action, new FormData($el))">
                                                                            @csrf
                                                                            <button type="submit" class="rounded-md bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-500">
                                                                                Missed
                                                                            </button>
                                                                        </form>

                                                                        <form method="POST" action="{{ route('crm.webinar-registrations.dev.reset.store', $registration) }}" x-on:submit.prevent="run('Reset Registration', $el.action, new FormData($el))">
                                                                            @csrf
                                                                            <button type="submit" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                                                Reset
                                                                            </button>
                                                                        </form>
                                                                    </div>

                                                                    <div
                                                                        x-data="{
                                                                            loading: false,
                                                                            loaded: false,
                                                                            selectedConfigPath: '',
                                                                            groups: [],
                                                                            error: null,
                                                                            async load() {
                                                                                if (this.loading) return;

                                                                                this.loading = true;
                                                                                this.error = null;

                                                                                try {
                                                                                    const response = await fetch(@js(route('crm.webinar-registrations.dev.message-options.index', $registration)), {
                                                                                        headers: {
                                                                                            'Accept': 'application/json',
                                                                                            'X-Requested-With': 'XMLHttpRequest',
                                                                                        },
                                                                                    });

                                                                                    if (! response.ok) {
                                                                                        throw new Error('Unable to load message definitions.');
                                                                                    }

                                                                                    const data = await response.json();
                                                                                    this.groups = data.messages || [];
                                                                                    this.loaded = true;
                                                                                    record('success', `Loaded ${this.groups.reduce((total, group) => total + ((group.definitions || []).length), 0)} message option(s).`);

                                                                                    const firstGroup = this.groups[0] || null;
                                                                                    const firstDefinition = firstGroup && firstGroup.definitions ? firstGroup.definitions[0] : null;
                                                                                    this.selectedConfigPath = firstDefinition ? firstDefinition.config_path : '';
                                                                                } catch (error) {
                                                                                    this.error = error.message || 'Unable to load message definitions.';
                                                                                    record('error', this.error);
                                                                                } finally {
                                                                                    this.loading = false;
                                                                                }
                                                                            }
                                                                        }"
                                                                        class="rounded-xl border border-slate-200 bg-slate-50 p-3"
                                                                    >
                                                                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                                                                            <button
                                                                                type="button"
                                                                                x-on:click="load()"
                                                                                class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                                                            >
                                                                                <span x-show="!loading && !loaded">Load confirmations/reminders</span>
                                                                                <span x-show="loading">Loading…</span>
                                                                                <span x-show="!loading && loaded">Refresh loaded</span>
                                                                            </button>

                                                                            <form method="POST" action="{{ route('crm.webinar-registrations.dev.messages.all.store', $registration) }}" x-on:submit.prevent="run('Send All Messages', $el.action, new FormData($el))">
                                                                                @csrf
                                                                                <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-slate-700">
                                                                                    Send All Now
                                                                                </button>
                                                                            </form>
                                                                        </div>

                                                                        <p x-show="error" x-text="error" class="mt-2 text-xs font-semibold text-red-700"></p>

                                                                        <form
                                                                            method="POST"
                                                                            action="{{ route('crm.webinar-registrations.dev.messages.store', $registration) }}"
                                                                            x-on:submit.prevent="run('Send Selected Message', $el.action, new FormData($el))"
                                                                            x-show="loaded && groups.length > 0"
                                                                            class="mt-3 grid gap-2 lg:grid-cols-[minmax(0,1fr)_auto]"
                                                                        >
                                                                            @csrf

                                                                            <select
                                                                                name="config_path"
                                                                                x-model="selectedConfigPath"
                                                                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                                                            >
                                                                                <template x-for="group in groups" :key="group.channel">
                                                                                    <optgroup :label="group.channel.toUpperCase()">
                                                                                        <template x-for="definition in group.definitions" :key="definition.config_path">
                                                                                            <option :value="definition.config_path" x-text="definition.label + ' — ' + group.channel.toUpperCase()"></option>
                                                                                        </template>
                                                                                    </optgroup>
                                                                                </template>
                                                                            </select>

                                                                            <button
                                                                                type="submit"
                                                                                class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                                                                                x-bind:disabled="!selectedConfigPath"
                                                                            >
                                                                                Send Selected Now
                                                                            </button>
                                                                        </form>

                                                                        <p x-show="loaded && groups.length === 0" class="mt-2 text-xs text-slate-500">
                                                                            No available transactional webinar message definitions for this registration.
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @empty
                                                        <div class="p-4 text-sm text-slate-500">
                                                            No registrations for this webinar yet.
                                                        </div>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>

                                        <aside class="space-y-4">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                                <h3 class="text-sm font-bold text-slate-950">
                                                    Replay & Follow-Ups
                                                </h3>

                                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                                    Follow-ups use the post-event dispatch action and require a replay URL.
                                                </p>

                                                <div class="mt-4 space-y-2">
                                                    <form method="POST" action="{{ route('crm.webinars.dev.replay-url.store', $webinar) }}" x-on:submit.prevent="run('Set Fake Replay', $el.action, new FormData($el))">
                                                        @csrf
                                                        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                                                            Set Fake Replay
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="{{ route('crm.webinars.dev.follow-ups.store', $webinar) }}" x-on:submit.prevent="run('Dispatch Follow-Ups', $el.action, new FormData($el))">
                                                        @csrf
                                                        <button type="submit" class="w-full rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">
                                                            Dispatch Follow-Ups
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="{{ route('crm.webinars.dev.replay-url.destroy', $webinar) }}" x-on:submit.prevent="run('Clear Replay', $el.action, new FormData($el))">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                            Clear Replay
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>

                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                                <h3 class="text-sm font-bold text-slate-950">
                                                    Activity Log
                                                </h3>

                                                <div class="mt-3 space-y-2 text-xs">
                                                    <template x-if="log.length === 0">
                                                        <p class="text-slate-500">
                                                            Run a dev action to see results here without reloading the page.
                                                        </p>
                                                    </template>

                                                    <template x-for="item in log" :key="item.time + item.message">
                                                        <div
                                                            class="rounded-lg border px-3 py-2"
                                                            x-bind:class="item.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-900'"
                                                        >
                                                            <div class="font-bold" x-text="item.time"></div>
                                                            <div class="mt-0.5" x-text="item.message"></div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>

                                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-900">
                                                <p class="font-bold">What these controls test</p>
                                                <ul class="mt-2 list-disc space-y-1 pl-4">
                                                    <li>Confirmation and reminder payloads through Messaging.</li>
                                                    <li>Join-click metadata and live reminder skipping.</li>
                                                    <li>webinar.attended / webinar.missed FlowRoute triggers.</li>
                                                    <li>Replay/post-event follow-up dispatch.</li>
                                                </ul>
                                            </div>
                                        </aside>
                                    </div>
                                </x-crm.dev-testing-modal>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-6 text-sm text-slate-600">
                                    No webinars found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">
                        Add Series
                    </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.store') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="title" class="block text-sm font-medium text-slate-700">
                                Series Title
                            </label>

                            <input
                                id="title"
                                name="title"
                                type="text"
                                value="{{ old('title') }}"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                placeholder="Exact Zoom webinar series title"
                                required
                            >

                            @error('title')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        >
                            Add Series
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">
                        Sync Series
                    </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.sync') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="webinar_series_id" class="block text-sm font-medium text-slate-700">
                                Webinar Series
                            </label>

                            <select
                                id="webinar_series_id"
                                name="webinar_series_id"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                required
                            >
                                <option value="">Select a series</option>

                                @foreach($series as $seriesItem)
                                    <option
                                        value="{{ $seriesItem->id }}"
                                        @selected(old('webinar_series_id') == $seriesItem->id)
                                    >
                                        {{ $seriesItem->title }}
                                    </option>
                                @endforeach
                            </select>

                            @error('webinar_series_id')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        >
                            Sync from Zoom
                        </button>
                    </form>
                </div>

                @if($series->isNotEmpty())
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-slate-900">
                            Series
                        </h2>

                        <div class="mt-4 space-y-2">
                            @foreach($series as $seriesItem)
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <span>{{ $seriesItem->title }}</span>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.webinar-series.destroy', $seriesItem) }}"
                                        onsubmit="return confirm('Delete this webinar series? This cannot be undone.');"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="text-xs font-semibold text-red-600 hover:text-red-800"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.crm>
