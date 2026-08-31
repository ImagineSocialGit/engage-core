<x-layouts.crm :title="$title" :heading="$heading">
    @php
        $requestedMessageWebinarId = request()->integer('messages');
        $initialMessageWebinarId = collect($upcomingWebinars ?? [])->contains(
            fn ($webinar): bool => (int) $webinar->getKey() === $requestedMessageWebinarId,
        ) ? $requestedMessageWebinarId : null;
    @endphp

    <div
        class="space-y-6"
        x-data="{
            activeDevTestingModal: null,
            activeMessageWebinar: @js($initialMessageWebinarId),
            webinarLinkOptions: @js(($webinarLinkOptions ?? collect())->all()),
            paidAdTrackingPlatforms: @js($paidAdTrackingPlatforms ?? []),
            activeLinksWebinar: null,
            linksModalOpen: false,
            linksReportingOpen: false,
            linksPlatformKey: 'meta',
            copiedLinksField: null,
            linksCopyFailed: false,
            openDevTestingModal(name) {
                this.activeDevTestingModal = name;
            },
            openMessageReview(webinarId) {
                this.activeMessageWebinar = webinarId;
            },
            closeMessageReview() {
                this.activeMessageWebinar = null;
            },
            openLinksModal(webinarId) {
                const key = String(webinarId);

                if (!this.webinarLinkOptions[key]) {
                    return;
                }

                this.activeLinksWebinar = key;
                this.linksModalOpen = true;
                this.linksReportingOpen = false;
                this.linksPlatformKey = 'meta';
                this.copiedLinksField = null;
                this.linksCopyFailed = false;
            },
            closeLinksModal() {
                this.linksModalOpen = false;
                this.linksReportingOpen = false;
                this.copiedLinksField = null;
                this.linksCopyFailed = false;
            },
            selectedLinkOption() {
                return this.webinarLinkOptions[String(this.activeLinksWebinar)] || {};
            },
            selectedAdPlatform() {
                return this.paidAdTrackingPlatforms[this.linksPlatformKey] || {};
            },
            selectLinksWebinar(webinarId) {
                this.activeLinksWebinar = String(webinarId);
                this.linksReportingOpen = false;
                this.copiedLinksField = null;
                this.linksCopyFailed = false;
            },
            async copyLinksValue(value, field) {
                if (!value) {
                    return;
                }

                this.linksCopyFailed = false;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(value);
                    } else {
                        const textarea = document.createElement('textarea');
                        textarea.value = value;
                        textarea.setAttribute('readonly', '');
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.focus();
                        textarea.select();

                        const copied = document.execCommand('copy');
                        textarea.remove();

                        if (!copied) {
                            throw new Error('copy_failed');
                        }
                    }

                    this.copiedLinksField = field;
                    setTimeout(() => {
                        if (this.copiedLinksField === field) {
                            this.copiedLinksField = null;
                        }
                    }, 1500);
                } catch (error) {
                    this.copiedLinksField = null;
                    this.linksCopyFailed = true;
                }
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

        @if(session('occurrence_replacement_result'))
            @php
                $replacementResult = session('occurrence_replacement_result');
                $replacementQueueCounts = $replacementResult['queue_status_counts'] ?? [];
            @endphp

            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-4 text-sm text-indigo-950">
                <p class="font-semibold">
                    Occurrence replacement: {{ $replacementResult['source_title'] }} → {{ $replacementResult['replacement_title'] }}
                </p>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-indigo-900">
                    <span>{{ $replacementResult['eligible_registrations'] }} eligible</span>
                    <span>{{ $replacementResult['created_registrations'] }} created</span>
                    <span>{{ $replacementResult['adopted_registrations'] }} adopted</span>
                    <span>{{ $replacementResult['skipped_source_messages'] }} obsolete messages skipped</span>
                    @foreach($replacementQueueCounts as $status => $count)
                        <span>{{ $count }} {{ \Illuminate\Support\Str::headline((string) $status) }}</span>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-indigo-800">
                    Each replacement registration finalizes independently. Failed or ambiguous registrations appear under Needs attention.
                </p>
            </div>
        @endif

        @if (session('zoom_sync_error'))
            <div
                x-data="{ open: true }"
                x-show="open"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 px-3 py-4 sm:px-4"
            >
                <div class="max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-4 shadow-xl sm:p-6">
                    <h2 class="text-lg font-bold text-gray-950">
                        Zoom Sync Failed
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-gray-700">
                        {{ session('zoom_sync_error') }}
                    </p>

                    <div class="mt-6 flex justify-stretch sm:justify-end">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="w-full rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white sm:w-auto"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if(session('sync_conflicts') && count(session('sync_conflicts')))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Active webinar event conflicts detected.</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_conflicts') as $conflict)
                        <li class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
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
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                <p class="font-semibold">
                    Zoom no longer includes {{ count(session('sync_missing')) }}
                    {{ count(session('sync_missing')) === 1 ? 'occurrence' : 'occurrences' }}.
                </p>
                <p class="mt-1 text-amber-900">
                    {{ count(session('sync_missing')) === 1 ? 'It was' : 'They were' }} removed from the active schedule and kept in Engage Core while you decide whether to replace or remove {{ count(session('sync_missing')) === 1 ? 'it' : 'them' }}. Review the next step under Needs attention.
                </p>
                <a
                    href="{{ route('crm.webinar-series.index', ['attention' => 1]) }}"
                    class="mt-2 inline-flex font-extrabold text-amber-950 underline decoration-amber-300 underline-offset-4 hover:text-amber-700"
                >
                    Review removed occurrences
                </a>
            </div>
        @endif

        <section
            data-webinar-workspace-shell
            class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(22rem,0.85fr)] xl:items-start"
        >
            <div
                data-webinar-workspace-main
                class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
            >
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                            Webinar workspace
                        </p>
                        <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">
                            Run webinars from one place.
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                            Review anything that needs a decision, check the next sessions, and open the message sequence without digging through setup controls.
                        </p>
                    </div>

                    <div class="grid gap-2 sm:flex sm:flex-wrap lg:justify-end">
                        @if(function_exists('module_enabled') && module_enabled('messaging') && \Illuminate\Support\Facades\Route::has('crm.webinars.message-templates.index'))
                            <a
                                href="{{ route('crm.webinars.message-templates.index') }}"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-center text-sm font-extrabold text-white transition hover:bg-slate-800 sm:w-auto"
                            >
                                Registration & follow-up messages
                            </a>
                        @endif

                        <a
                            href="#advanced-webinar-setup"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-center text-sm font-extrabold text-slate-700 transition hover:bg-slate-50 sm:w-auto"
                        >
                            Manage webinar setup
                        </a>
                    </div>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border {{ ($attentionCount ?? 0) > 0 ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} px-4 py-4">
                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] {{ ($attentionCount ?? 0) > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                            Needs attention
                        </p>
                        <p class="mt-1 text-2xl font-black {{ ($attentionCount ?? 0) > 0 ? 'text-amber-950' : 'text-emerald-950' }}">
                            {{ $attentionCount ?? 0 }}
                        </p>
                        <p class="mt-1 text-xs leading-5 {{ ($attentionCount ?? 0) > 0 ? 'text-amber-800' : 'text-emerald-800' }}">
                            {{ ($attentionCount ?? 0) > 0 ? 'Review before normal delivery continues.' : 'Nothing is waiting on you.' }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4">
                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-sky-700">Next sessions shown</p>
                        <p class="mt-1 text-2xl font-black text-sky-950">{{ ($upcomingWebinars ?? collect())->count() }}</p>
                        <p class="mt-1 text-xs leading-5 text-sky-800">The next active sessions listed here.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-600">Webinar series</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ ($series ?? collect())->count() }}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Series available to refresh or manage.</p>
                    </div>
                </div>

                <div data-webinar-workspace-attention class="mt-7 border-t border-slate-200 pt-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-black tracking-tight text-slate-950">What needs your attention</h3>
                                <span class="rounded-full {{ ($attentionCount ?? 0) > 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }} px-2.5 py-1 text-xs font-extrabold">
                                    {{ $attentionCount ?? 0 }} {{ ($attentionCount ?? 0) === 1 ? 'item' : 'items' }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Only work that needs a decision or recovery action appears here.
                            </p>
                        </div>

                        @if(($registrationAttentionCount ?? 0) > 0)
                            <a
                                href="{{ route('crm.webinar-series.index', ['attention' => 1]) }}"
                                class="text-sm font-extrabold text-amber-800 underline decoration-amber-300 underline-offset-4 hover:text-amber-950"
                            >
                                Open registration recovery
                            </a>
                        @endif
                    </div>

                    @if(($attentionCount ?? 0) === 0)
                        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-900">
                            <p class="font-bold">Everything is clear right now.</p>
                            <p class="mt-1 text-emerald-800">The next webinar can continue on its configured schedule.</p>
                        </div>
                    @else
                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            @foreach(($providerMissingOccurrences ?? collect()) as $missingWebinar)
                                <article
                                    data-provider-missing-occurrence="{{ $missingWebinar->getKey() }}"
                                    class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4"
                                >
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-amber-700">Removed from Zoom</p>
                                            <h4 class="mt-1 text-base font-black text-slate-950">{{ $missingWebinar->title }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">
                                                {{ $missingWebinar->starts_at?->copy()->setTimezone($missingWebinar->timezone)->format('M j, Y · g:i A T') ?? 'Start time unavailable' }}
                                                · {{ (int) ($missingWebinar->registrations_count ?? 0) }} {{ (int) ($missingWebinar->registrations_count ?? 0) === 1 ? 'registration' : 'registrations' }}
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-extrabold text-amber-800">Decision needed</span>
                                    </div>

                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                        This event is no longer on the Zoom schedule. Choose a replacement for active registrants, or keep it for history.
                                    </p>
                                    <a
                                        href="{{ route('crm.webinar-series.index', ['attention' => 1]).'#webinar-'.$missingWebinar->getKey() }}"
                                        class="mt-3 inline-flex min-h-10 w-full items-center justify-center rounded-full bg-amber-700 px-4 text-center text-sm font-extrabold text-white transition hover:bg-amber-600 sm:w-auto"
                                    >
                                        Review next step
                                    </a>
                                </article>
                            @endforeach

                            @foreach(($pendingPostEventReviews ?? collect()) as $reviewWebinar)
                                <article class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-amber-700">Follow-up review</p>
                                            <h4 class="mt-1 text-base font-black text-slate-950">{{ $reviewWebinar->title }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">
                                                {{ (int) ($reviewWebinar->attended_registrations_count ?? 0) }} attended · {{ (int) ($reviewWebinar->missed_registrations_count ?? 0) }} missed
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-extrabold text-amber-800">Waiting</span>
                                    </div>

                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                        Confirm the replay plan before replay-dependent follow-ups continue.
                                    </p>
                                    <a
                                        href="{{ route('crm.webinars.post-event-review.show', $reviewWebinar) }}"
                                        class="mt-3 inline-flex min-h-10 w-full items-center justify-center rounded-full bg-amber-700 px-4 text-center text-sm font-extrabold text-white transition hover:bg-amber-600 sm:w-auto"
                                    >
                                        Review follow-ups
                                    </a>
                                </article>
                            @endforeach

                            @if(($registrationAttentionCount ?? 0) > 0)
                                <article class="rounded-2xl border border-red-200 bg-red-50/70 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-red-700">Registration recovery</p>
                                            <h4 class="mt-1 text-base font-black text-slate-950">
                                                {{ $registrationAttentionCount }} {{ $registrationAttentionCount === 1 ? 'registration needs' : 'registrations need' }} review
                                            </h4>
                                        </div>
                                        <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-extrabold text-red-800">Action needed</span>
                                    </div>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                        These registrations did not finish cleanly in Zoom and need a decision before normal confirmation can continue.
                                    </p>
                                    <a
                                        href="{{ route('crm.webinar-series.index', ['attention' => 1]) }}"
                                        class="mt-3 inline-flex min-h-10 w-full items-center justify-center rounded-full bg-red-700 px-4 text-center text-sm font-extrabold text-white transition hover:bg-red-600 sm:w-auto"
                                    >
                                        Review registrations
                                    </a>
                                </article>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <aside
                data-upcoming-webinars
                data-upcoming-webinars-side-panel
                class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-sky-700">Next on the calendar</p>
                        <h2 class="mt-1 text-xl font-black tracking-tight text-slate-950">Upcoming webinars</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Open messages or event details without leaving the workspace.</p>
                    </div>
                    <span class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-extrabold text-sky-700 ring-1 ring-sky-200">
                        {{ ($upcomingWebinars ?? collect())->count() }}
                    </span>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse(($upcomingWebinars ?? collect()) as $upcomingWebinar)
                        @php
                            $upcomingRegistrationUrl = filled($upcomingWebinar->webinarSeries?->slug)
                                ? route('webinar.show', ['seriesSlug' => $upcomingWebinar->webinarSeries->slug])
                                : null;
                            $isLive = $upcomingWebinar->starts_at?->lte(now()) && $upcomingWebinar->ends_at?->gt(now());
                            $registrationCount = (int) ($upcomingWebinar->registrations_count ?? 0);
                            $messagePurposeReviews = $upcomingMessagePurposeReviews[$upcomingWebinar->getKey()] ?? [];
                            $hasMessageReview = collect($messagePurposeReviews)->contains(
                                fn ($review): bool => is_array($review)
                                    && (int) ($review['message_count'] ?? 0) > 0,
                            );
                        @endphp

                        <article class="rounded-2xl border {{ $isLive ? 'border-emerald-200 bg-emerald-50/70' : 'border-slate-200 bg-slate-50' }} p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-extrabold uppercase tracking-[0.14em] {{ $isLive ? 'text-emerald-700' : 'text-slate-500' }}">
                                        {{ $isLive ? 'Live now' : 'Upcoming' }}
                                    </p>
                                    <h3 class="mt-1 truncate text-sm font-black text-slate-950" title="{{ $upcomingWebinar->title }}">
                                        {{ $upcomingWebinar->title }}
                                    </h3>
                                    <p class="mt-1 text-xs text-slate-500">{{ $upcomingWebinar->webinarSeries?->title ?? 'Webinar series' }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-white px-2 py-1 text-[11px] font-extrabold text-slate-700 ring-1 ring-slate-200">
                                    {{ $registrationCount }}
                                </span>
                            </div>

                            <p class="mt-3 text-sm font-bold text-slate-900">
                                {{ $upcomingWebinar->starts_at?->copy()->setTimezone($upcomingWebinar->timezone)->format('M j · g:i A T') ?? 'Start time pending' }}
                            </p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($hasMessageReview)
                                    <button
                                        type="button"
                                        data-webinar-message-review-button
                                        x-on:click="openMessageReview({{ $upcomingWebinar->getKey() }})"
                                        class="inline-flex min-h-8 items-center justify-center rounded-full bg-slate-950 px-3 text-[11px] font-extrabold text-white hover:bg-slate-800"
                                    >
                                        View messages
                                    </button>
                                @endif

                                <a
                                    href="#webinar-{{ $upcomingWebinar->getKey() }}"
                                    class="inline-flex min-h-8 items-center justify-center rounded-full border border-slate-300 bg-white px-3 text-[11px] font-extrabold text-slate-700 hover:bg-slate-100"
                                >
                                    Event details
                                </a>

                                @if($upcomingRegistrationUrl)
                                    <button
                                        type="button"
                                        data-webinar-get-links="{{ $upcomingWebinar->getKey() }}"
                                        x-on:click="openLinksModal({{ $upcomingWebinar->getKey() }})"
                                        class="inline-flex min-h-8 items-center justify-center rounded-full bg-sky-700 px-3 text-[11px] font-extrabold text-white hover:bg-sky-600"
                                    >
                                        Get Links
                                    </button>

                                    <a
                                        href="{{ $upcomingRegistrationUrl }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex min-h-8 items-center justify-center rounded-full border border-slate-300 bg-white px-3 text-[11px] font-extrabold text-slate-700 hover:bg-slate-100"
                                    >
                                        Registration page
                                    </a>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
                            <p class="font-bold text-slate-900">No upcoming webinars are scheduled.</p>
                            <p class="mt-1 text-sm text-slate-600">Open webinar setup when you are ready to add or refresh the next series.</p>
                        </div>
                    @endforelse
                </div>

                <a
                    href="#event-operations"
                    class="mt-4 inline-flex text-sm font-extrabold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                >
                    See all event details
                </a>
            </aside>
        </section>

        @if(($webinarLinkOptions ?? collect())->isNotEmpty())
            <div
                x-show="linksModalOpen"
                x-cloak
                x-on:keydown.escape.window="if (linksModalOpen) closeLinksModal()"
                x-on:click.self="closeLinksModal()"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-4"
                data-webinar-links-modal
            >
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="webinar-links-modal-title"
                    class="my-auto max-h-[calc(100vh-2rem)] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
                >
                    <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-sky-700">Webinar links</p>
                            <h2 id="webinar-links-modal-title" class="mt-1 text-xl font-black tracking-tight text-slate-950 sm:text-2xl">
                                Get Links
                            </h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Copy the normal registration link, or open the reporting section when you are setting up a paid ad.
                            </p>
                        </div>

                        <button
                            type="button"
                            x-on:click="closeLinksModal()"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl font-bold text-slate-600 hover:bg-slate-50 hover:text-slate-950"
                            aria-label="Close Get Links"
                        >
                            ×
                        </button>
                    </div>

                    <div class="space-y-5 px-5 py-5 sm:px-6 sm:py-6">
                        <label class="grid gap-2 text-sm font-extrabold text-slate-800">
                            Webinar
                            <select
                                x-bind:value="activeLinksWebinar || ''"
                                x-on:change="selectLinksWebinar($event.target.value)"
                                class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200"
                            >
                                @foreach(($webinarLinkOptions ?? collect()) as $webinarId => $linkOption)
                                    <option value="{{ $webinarId }}">{{ $linkOption['option_label'] }}</option>
                                @endforeach
                            </select>
                        </label>

                        <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
                                        Webinar registration link
                                    </p>
                                    <p class="mt-1 text-sm font-bold text-slate-950" x-text="selectedLinkOption().webinar_title || 'Webinar'"></p>
                                    <p
                                        class="mt-1 text-xs text-slate-500"
                                        x-show="selectedLinkOption().starts_at_label"
                                        x-text="selectedLinkOption().starts_at_label"
                                    ></p>
                                </div>

                                <a
                                    x-bind:href="selectedLinkOption().destination_url || '#'"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs font-extrabold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900"
                                >
                                    Open page
                                </a>
                            </div>

                            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                <input
                                    type="text"
                                    readonly
                                    x-bind:value="selectedLinkOption().destination_url || ''"
                                    class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800"
                                    aria-label="Webinar registration link"
                                >
                                <button
                                    type="button"
                                    x-on:click="copyLinksValue(selectedLinkOption().destination_url || '', 'destination')"
                                    class="min-h-11 shrink-0 rounded-xl bg-slate-950 px-5 text-sm font-extrabold text-white hover:bg-slate-800"
                                >
                                    <span x-text="copiedLinksField === 'destination' ? 'Copied' : 'Copy full URL'"></span>
                                </button>
                            </div>
                        </section>

                        @if(! empty($paidAdTrackingPlatforms ?? []))
                            <section class="overflow-hidden rounded-2xl border border-sky-200 bg-white" data-webinar-ad-reporting-section>
                                <button
                                    type="button"
                                    x-on:click="linksReportingOpen = !linksReportingOpen; copiedLinksField = null; linksCopyFailed = false"
                                    class="flex min-h-14 w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-sky-50 sm:px-5"
                                    aria-controls="webinar-ad-reporting-links"
                                    x-bind:aria-expanded="linksReportingOpen ? 'true' : 'false'"
                                    data-webinar-ad-reporting-toggle
                                >
                                    <span>
                                        <span class="block text-sm font-black text-slate-950">Get ad links for reporting</span>
                                        <span class="mt-0.5 block text-xs leading-5 text-slate-600">
                                            Meta, TikTok, and YouTube tracking setup.
                                        </span>
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        class="text-xl font-bold text-sky-700 transition"
                                        x-bind:class="linksReportingOpen ? 'rotate-180' : ''"
                                    >
                                        ⌄
                                    </span>
                                </button>

                                <div
                                    id="webinar-ad-reporting-links"
                                    x-show="linksReportingOpen"
                                    x-cloak
                                    class="border-t border-sky-200 bg-sky-50/40 p-4 sm:p-5"
                                    data-webinar-ad-reporting-links
                                >
                                    <p class="text-sm leading-6 text-slate-700">
                                        Use the normal webinar URL above as the ad destination. Then add the tracking text below in the matching field in your ad platform.
                                    </p>

                                    <div class="mt-4">
                                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">Ad platform</p>
                                        <div class="mt-2 grid grid-cols-3 gap-2" role="group" aria-label="Ad platform">
                                            @foreach(($paidAdTrackingPlatforms ?? []) as $platformKey => $platform)
                                                <button
                                                    type="button"
                                                    x-on:click="linksPlatformKey = @js($platformKey); copiedLinksField = null; linksCopyFailed = false"
                                                    x-bind:class="linksPlatformKey === @js($platformKey)
                                                        ? 'border-sky-700 bg-sky-700 text-white'
                                                        : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                                                    class="min-h-10 rounded-xl border px-3 py-2 text-xs font-extrabold transition sm:text-sm"
                                                >
                                                    {{ $platform['short_label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500" x-text="selectedAdPlatform().destination_label || 'Destination URL'"></p>
                                        <p class="mt-1 text-sm leading-6 text-slate-700">
                                            Use the webinar registration URL shown above. Do not add the tracking text directly to that URL when the ad platform provides the separate field shown below.
                                        </p>
                                    </div>

                                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                            <div>
                                                <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500" x-text="selectedAdPlatform().parameters_label || 'Tracking parameters'"></p>
                                                <p class="mt-1 text-sm font-bold text-slate-950">Copy this exactly as shown.</p>
                                            </div>
                                        </div>

                                        <textarea
                                            readonly
                                            rows="5"
                                            x-bind:value="selectedAdPlatform().parameters || ''"
                                            class="mt-3 w-full resize-y rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs leading-5 text-slate-800"
                                            aria-label="Ad reporting tracking parameters"
                                        ></textarea>

                                        <div class="mt-2 flex justify-end">
                                            <button
                                                type="button"
                                                x-on:click="copyLinksValue(selectedAdPlatform().parameters || '', 'ad-parameters')"
                                                class="rounded-xl bg-sky-700 px-4 py-2 text-sm font-extrabold text-white hover:bg-sky-600"
                                            >
                                                <span x-text="copiedLinksField === 'ad-parameters' ? 'Copied' : 'Copy tracking text'"></span>
                                            </button>
                                        </div>
                                    </div>

                                    <div
                                        x-show="(selectedAdPlatform().custom_parameters || []).length > 0"
                                        x-cloak
                                        class="mt-4 rounded-2xl border border-indigo-200 bg-white p-4"
                                    >
                                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-indigo-700">Google Ads custom parameters</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Create these at the matching level and use the readable name already in Google Ads as the value.
                                        </p>

                                        <div class="mt-3 overflow-x-auto">
                                            <table class="min-w-full text-left text-sm">
                                                <thead>
                                                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                                        <th class="px-2 py-2 font-extrabold">Where</th>
                                                        <th class="px-2 py-2 font-extrabold">Parameter</th>
                                                        <th class="px-2 py-2 font-extrabold">Value</th>
                                                        <th class="px-2 py-2"><span class="sr-only">Copy</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="parameter in (selectedAdPlatform().custom_parameters || [])" x-bind:key="parameter.key">
                                                        <tr class="border-b border-slate-100 last:border-0">
                                                            <td class="px-2 py-3 font-bold text-slate-900" x-text="parameter.level"></td>
                                                            <td class="px-2 py-3 font-mono text-xs text-slate-800" x-text="parameter.key"></td>
                                                            <td class="px-2 py-3 text-slate-600" x-text="parameter.value_hint"></td>
                                                            <td class="px-2 py-3 text-right">
                                                                <button
                                                                    type="button"
                                                                    x-on:click="copyLinksValue(parameter.key, 'custom-' + parameter.key)"
                                                                    class="text-xs font-extrabold text-indigo-700 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-900"
                                                                >
                                                                    <span x-text="copiedLinksField === ('custom-' + parameter.key) ? 'Copied' : 'Copy key'"></span>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">Where it goes</p>
                                            <p class="mt-2 text-sm leading-6 text-slate-700" x-text="selectedAdPlatform().instructions || ''"></p>
                                        </div>

                                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                            <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-amber-700">Before publishing</p>
                                            <ul class="mt-2 space-y-2 text-sm leading-5 text-amber-950">
                                                <template x-for="note in (selectedAdPlatform().notes || [])" x-bind:key="note">
                                                    <li class="flex gap-2">
                                                        <span aria-hidden="true">•</span>
                                                        <span x-text="note"></span>
                                                    </li>
                                                </template>
                                                <li class="flex gap-2">
                                                    <span aria-hidden="true">•</span>
                                                    <span>Use the ad platform’s Preview or Test option and confirm the correct webinar page opens.</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <p class="mt-4 text-xs leading-5 text-slate-600">
                                        Click IDs such as <code class="font-bold">fbclid</code>, <code class="font-bold">gclid</code>, and <code class="font-bold">ttclid</code> are added by the ad platforms when applicable. Do not add them manually.
                                    </p>
                                </div>
                            </section>
                        @endif

                        <p
                            x-show="linksCopyFailed"
                            x-cloak
                            class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800"
                        >
                            Automatic copy did not work in this browser. Select the field and copy it manually.
                        </p>
                    </div>
                </div>
            </div>
        @endif

@foreach(($upcomingWebinars ?? collect()) as $upcomingWebinar)
    @php
        $messagePurposeReviews = $upcomingMessagePurposeReviews[$upcomingWebinar->getKey()] ?? [];
        $messagePurposeReviews = collect($messagePurposeReviews)
            ->filter(fn ($review): bool => is_array($review)
                && (int) ($review['message_count'] ?? 0) > 0)
            ->all();
        $hasMessageReview = $messagePurposeReviews !== [];
        $initialMessagePurpose = array_key_first($messagePurposeReviews) ?: 'transactional';
        $totalMessageCount = collect($messagePurposeReviews)->sum(
            fn (array $review): int => (int) ($review['message_count'] ?? 0),
        );
        $messageProfile = $upcomingMessageProfiles[$upcomingWebinar->getKey()] ?? [];
        $effectiveProfileName = filled($messageProfile['effective_profile_name'] ?? null)
            ? (string) $messageProfile['effective_profile_name']
            : 'No active profile';
        $inheritedProfileName = filled($messageProfile['inherited_profile_name'] ?? null)
            ? (string) $messageProfile['inherited_profile_name']
            : 'No active profile';
        $profileSourceLabel = match ($messageProfile['source'] ?? 'default') {
            'occurrence' => 'Occurrence override',
            'series' => 'Series plan',
            default => 'Default plan',
        };
    @endphp

    @if($hasMessageReview)
        <div
            x-data="{ activeMessagePurpose: @js($initialMessagePurpose) }"
            x-show="activeMessageWebinar === {{ $upcomingWebinar->getKey() }}"
            x-cloak
            x-on:keydown.escape.window="closeMessageReview()"
            x-on:click.self="closeMessageReview()"
            data-webinar-message-review-modal="{{ $upcomingWebinar->getKey() }}"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-6"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="{{ $upcomingWebinar->title }} messages"
                class="max-h-[calc(100vh-2rem)] w-full max-w-6xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
            >
                <header class="sticky top-0 z-30 grid gap-4 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,28rem)_auto] lg:items-start">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">
                            Upcoming webinar messages
                        </p>
                        <h2 class="mt-1 text-xl font-black tracking-tight text-slate-950">
                            {{ $upcomingWebinar->title }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $upcomingWebinar->starts_at?->copy()->setTimezone($upcomingWebinar->timezone)->format('M j, Y · g:i A') }}
                            · {{ $totalMessageCount }} {{ $totalMessageCount === 1 ? 'message' : 'messages' }}
                        </p>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.webinars.schedule-profile.update', $upcomingWebinar) }}"
                        data-webinar-message-profile-form
                        class="rounded-2xl border border-slate-200 bg-slate-50 p-3"
                    >
                        @csrf
                        @method('PATCH')

                        <label class="block text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-500">
                            Message plan
                        </label>

                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <select
                                name="webinar_schedule_profile_id"
                                data-webinar-message-profile-select
                                class="min-h-10 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                            >
                                <option value="">
                                    Use inherited — {{ $inheritedProfileName }}
                                </option>
                                @foreach(($scheduleProfiles ?? collect()) as $scheduleProfile)
                                    <option
                                        value="{{ $scheduleProfile->getKey() }}"
                                        @selected((int) $upcomingWebinar->webinar_schedule_profile_id === (int) $scheduleProfile->getKey())
                                    >
                                        {{ $scheduleProfile->name }}{{ $scheduleProfile->is_default ? ' (default)' : '' }}
                                    </option>
                                @endforeach
                            </select>

                            <button
                                type="submit"
                                class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 text-xs font-extrabold text-slate-700 hover:bg-slate-100"
                            >
                                Apply
                            </button>
                        </div>

                        <p class="mt-2 text-xs text-slate-500">
                            In use: <span class="font-bold text-slate-700">{{ $effectiveProfileName }}</span>
                            · {{ $profileSourceLabel }}
                        </p>
                    </form>

                    <button
                        type="button"
                        x-on:click="closeMessageReview()"
                        class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-50 lg:justify-self-end"
                    >
                        Close
                    </button>
                </header>

                <div class="p-4 sm:p-6">
                    @if(count($messagePurposeReviews) > 1)
                        <div
                            data-webinar-message-purpose-switcher
                            class="mb-4 flex flex-wrap items-center justify-center gap-2"
                        >
                            @foreach($messagePurposeReviews as $purpose => $purposeReview)
                                <button
                                    type="button"
                                    data-webinar-message-purpose="{{ $purpose }}"
                                    x-on:click="activeMessagePurpose = @js($purpose)"
                                    x-bind:class="activeMessagePurpose === @js($purpose)
                                        ? 'bg-slate-950 text-white ring-slate-950'
                                        : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'"
                                    class="inline-flex min-h-10 items-center gap-2 rounded-full px-4 text-sm font-extrabold ring-1 transition"
                                >
                                    <span>{{ \Illuminate\Support\Str::headline((string) $purpose) }}</span>
                                    <span
                                        x-bind:class="activeMessagePurpose === @js($purpose)
                                            ? 'bg-white/15 text-white'
                                            : 'bg-slate-100 text-slate-600'"
                                        class="rounded-full px-2 py-0.5 text-xs"
                                    >
                                        {{ (int) ($purposeReview['message_count'] ?? 0) }}
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @foreach($messagePurposeReviews as $purpose => $purposeReview)
                        <div
                            x-show="activeMessagePurpose === @js($purpose)"
                            x-cloak
                            data-webinar-message-purpose-panel="{{ $purpose }}"
                        >
                            <x-messaging.message-editor-carousel
                                :presentation="$purposeReview"
                                :editable="true"
                                :form-context="['webinar_id' => $upcomingWebinar->getKey()]"
                            />
                        </div>
                    @endforeach
                </div>

                <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="text-xs leading-5 text-slate-500">
                        Review and edit the published sequence here. Saving publishes a new immutable version for future Webinar messaging; existing scheduled or enrolled messages remain pinned to the versions they already use.
                    </p>

                    @if($upcomingWebinar->webinarSeries)
                        <a
                            href="{{ route('crm.webinar-series.message-chains.show', $upcomingWebinar->webinarSeries) }}"
                            class="inline-flex min-h-10 w-full shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-50 sm:w-auto"
                        >
                            Open full sequence
                        </a>
                    @endif
                </footer>
            </div>
        </div>
    @endif
@endforeach

        <div id="event-operations" class="space-y-6">
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <h2 class="text-sm font-semibold text-slate-900">
                        {{ $showAttention ? 'Registrations that need review' : ($showArchived ? 'Webinar history' : 'Upcoming event details') }}
                    </h2>

                    <div class="flex flex-wrap items-center gap-3 text-sm font-medium">
                        <a
                            href="{{ route('crm.webinar-series.index') }}"
                            class="{{ ! $showArchived && ! $showAttention ? 'text-slate-950' : 'text-slate-600' }} underline hover:text-slate-900"
                        >
                            Upcoming
                        </a>

                        <a
                            href="{{ route('crm.webinar-series.index', ['attention' => 1]) }}"
                            class="{{ $showAttention ? 'text-red-700' : 'text-slate-600' }} underline hover:text-red-700"
                        >
                            Needs attention
                        </a>

                        <a
                            href="{{ route('crm.webinar-series.index', ['archived' => 1]) }}"
                            class="{{ $showArchived && ! $showAttention ? 'text-slate-950' : 'text-slate-600' }} underline hover:text-slate-900"
                        >
                            History
                        </a>
                    </div>
                </div>

                <table class="min-w-[64rem] text-sm">
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
                                $registrationUrl = filled($webinar->webinarSeries?->slug)
                                    ? route('webinar.show', [
                                        'seriesSlug' => $webinar->webinarSeries->slug,
                                    ])
                                    : null;
                                $eventTypeLabel = $providerEventTypeOptions[$webinar->providerEventTypeKey()]
                                    ?? \Illuminate\Support\Str::headline($webinar->providerEventTypeKey());
                                $replacementCandidates = $replacementCandidatesBySourceId[$webinar->getKey()]
                                    ?? collect();
                                $providerMissing = $webinar->isProviderMissing();
                                $providerArchived = $webinar->isProviderArchived();
                                $hidden = $webinar->isHidden();
                                $replacementRegistrations = $webinar->registrations->filter(
                                    fn ($registration): bool => $registration->replacement_of_registration_id !== null,
                                );
                                $replacementCompleted = $replacementRegistrations->filter(
                                    fn ($registration): bool => in_array(data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ), ['completed'], true),
                                );
                                $replacementPending = $replacementRegistrations->filter(
                                    fn ($registration): bool => in_array(data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ), ['pending', 'queued', 'processing'], true),
                                );
                                $replacementAttention = $replacementRegistrations->filter(
                                    fn ($registration): bool => in_array(data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ), ['failed', 'reconciliation_required'], true)
                                        || data_get(
                                            $registration->meta,
                                            'provider_sync.status',
                                        ) === 'reconciliation_required',
                                );

                                $attendanceCheckedAt = data_get(
                                    $webinar->meta,
                                    'normalized.post_event.attendance_checked_at',
                                );
                                $attendanceReady = data_get(
                                    $webinar->meta,
                                    'normalized.post_event.attendance_ready',
                                ) === true;
                                $attendanceSnapshotReason = data_get(
                                    $webinar->meta,
                                    'normalized.post_event.attendance_snapshot_reason',
                                );
                                $attendanceSnapshotWarning = filled($attendanceSnapshotReason)
                                    ? \Illuminate\Support\Str::headline((string) $attendanceSnapshotReason)
                                    : null;

                                $finalizationFailures = $webinar->registrations->filter(
                                    fn ($registration): bool => data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ) === 'failed' && data_get(
                                        $registration->meta,
                                        'provider_sync.status',
                                    ) !== 'reconciliation_required',
                                );
                                $finalizationReconciliations = $webinar->registrations->filter(
                                    fn ($registration): bool => data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ) === 'reconciliation_required' || data_get(
                                        $registration->meta,
                                        'provider_sync.status',
                                    ) === 'reconciliation_required',
                                );
                                $finalizationsPending = $webinar->registrations->filter(
                                    fn ($registration): bool => in_array(data_get(
                                        $registration->meta,
                                        'registration_finalization.status',
                                    ), ['pending', 'queued', 'processing'], true),
                                );

                                $providerCancellationFailures = $webinar->registrations->filter(
                                    fn ($registration): bool => data_get(
                                        $registration->meta,
                                        'provider_cancellation.status',
                                    ) === 'failed',
                                );
                                $providerCancellationsPending = $webinar->registrations->filter(
                                    fn ($registration): bool => in_array(data_get(
                                        $registration->meta,
                                        'provider_cancellation.status',
                                    ), ['pending', 'cancelling'], true),
                                );
                                $followUpFailures = $webinar->registrations->filter(
                                    fn ($registration): bool => data_get(
                                        $registration->meta,
                                        'post_event_follow_up.status',
                                    ) === 'failed',
                                );
                                $followUpsPending = $webinar->registrations->filter(
                                    fn ($registration): bool => data_get(
                                        $registration->meta,
                                        'post_event_follow_up.status',
                                    ) === 'planning',
                                );

                                $modalName = 'webinar-dev-testing-'.$webinar->id;
                            @endphp

                            <tr id="webinar-{{ $webinar->getKey() }}" class="scroll-mt-24 hover:bg-slate-50">
                                <td class="px-6 py-4 font-medium text-slate-900">
                                    {{ $webinar->title }}

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                            Zoom {{ $eventTypeLabel }}
                                        </span>

                                        @if($hidden)
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200">
                                                Hidden from new registrations
                                            </span>
                                        @endif

                                        @if($providerMissing)
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                Removed from Zoom
                                            </span>
                                        @elseif($providerArchived)
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200">
                                                History
                                            </span>
                                        @endif

                                        @if($webinar->replacementOf)
                                            <span class="rounded-full bg-sky-50 px-2 py-0.5 font-semibold text-sky-700 ring-1 ring-sky-200">
                                                Replaces #{{ $webinar->replacementOf->getKey() }}
                                            </span>
                                        @endif

                                        @if($webinar->replacement)
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                Replaced by #{{ $webinar->replacement->getKey() }}
                                            </span>
                                        @endif

                                        @if($webinar->playback_url)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                Replay set
                                            </span>
                                        @endif

                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">
                                            {{ $webinar->registrations->count() }} registrations
                                        </span>

                                        @if($replacementRegistrations->isNotEmpty())
                                            <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                                {{ $replacementCompleted->count() }} replacement completed
                                            </span>

                                            @if($replacementPending->isNotEmpty())
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                    {{ $replacementPending->count() }} replacement pending
                                                </span>
                                            @endif

                                            @if($replacementAttention->isNotEmpty())
                                                <span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">
                                                    {{ $replacementAttention->count() }} replacement needs attention
                                                </span>
                                            @endif
                                        @endif

                                        @if($attendanceReady)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                Attendance reconciled
                                            </span>
                                        @elseif(filled($attendanceCheckedAt))
                                            <span
                                                class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200"
                                                title="{{ $attendanceSnapshotWarning ?? 'Attendance has not been finalized.' }}"
                                            >
                                                Attendance unresolved{{ $attendanceSnapshotWarning ? ': '.$attendanceSnapshotWarning : '' }}
                                            </span>
                                        @endif

                                        @if($attendanceReady && $attendanceSnapshotWarning)
                                            <span
                                                class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200"
                                                title="The prior finalized attendance remains in effect."
                                            >
                                                Latest attendance check: {{ $attendanceSnapshotWarning }}
                                            </span>
                                        @endif

                                        @if($finalizationFailures->isNotEmpty())
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">
                                                {{ $finalizationFailures->count() }} registration {{ $finalizationFailures->count() === 1 ? 'problem' : 'problems' }}
                                            </span>
                                        @endif

                                        @if($finalizationReconciliations->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $finalizationReconciliations->count() }} Zoom {{ $finalizationReconciliations->count() === 1 ? 'check' : 'checks' }} needed
                                            </span>
                                        @endif

                                        @if($finalizationsPending->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $finalizationsPending->count() }} registration {{ $finalizationsPending->count() === 1 ? 'setup' : 'setups' }} pending
                                            </span>
                                        @endif

                                        @if($providerCancellationFailures->isNotEmpty())
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">
                                                {{ $providerCancellationFailures->count() }} Zoom cancellation {{ $providerCancellationFailures->count() === 1 ? 'problem' : 'problems' }}
                                            </span>
                                        @endif

                                        @if($providerCancellationsPending->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $providerCancellationsPending->count() }} Zoom {{ $providerCancellationsPending->count() === 1 ? 'cancellation' : 'cancellations' }} pending
                                            </span>
                                        @endif

                                        @if($followUpFailures->isNotEmpty())
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">
                                                {{ $followUpFailures->count() }} follow-up planning {{ $followUpFailures->count() === 1 ? 'failure' : 'failures' }}
                                            </span>
                                        @endif

                                        @if($followUpsPending->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $followUpsPending->count() }} follow-up {{ $followUpsPending->count() === 1 ? 'attempt' : 'attempts' }} in progress
                                            </span>
                                        @endif
                                    </div>

                                    @if($providerMissing)
                                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950">
                                            <p class="font-semibold">This event no longer exists in Zoom and is no longer active or registerable.</p>
                                            <p class="mt-1 text-amber-900">
                                                @if($webinar->registrations->isNotEmpty())
                                                    It has registrations. Choose a synced replacement below so active registrants can be moved safely, or remove it from new registrations while preserving those registrations and their history.
                                                @else
                                                    Choose a synced replacement below, or remove the event from Engage Core.
                                                @endif
                                            </p>
                                        </div>
                                    @endif

                                    @if($webinar->replacement)
                                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950">
                                            <p class="font-semibold">
                                                This occurrence was replaced by
                                                {{ $webinar->replacement->title }}
                                                @if($webinar->replacement->starts_at)
                                                    on {{ $webinar->replacement->starts_at->copy()->setTimezone($webinar->replacement->timezone)->format('M j, Y g:i A') }}
                                                @endif
                                                .
                                            </p>
                                            <p class="mt-1 text-amber-900">
                                                Existing join tokens resolve through the replacement registration chain.
                                            </p>
                                        </div>
                                    @elseif($replacementCandidates->isNotEmpty())
                                        <details class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-800">
                                            <summary class="cursor-pointer font-semibold text-slate-950">
                                                Replace this occurrence
                                            </summary>

                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinars.replacements.store', $webinar) }}"
                                                class="mt-3 space-y-3"
                                            >
                                                @csrf

                                                <label class="grid gap-1 font-semibold text-slate-800">
                                                    Replacement occurrence
                                                    <select
                                                        name="replacement_webinar_id"
                                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900"
                                                        required
                                                    >
                                                        <option value="">Select the synced replacement</option>
                                                        @foreach($replacementCandidates as $candidate)
                                                            @php
                                                                $candidateTypeLabel = $providerEventTypeOptions[$candidate->providerEventTypeKey()]
                                                                    ?? \Illuminate\Support\Str::headline($candidate->providerEventTypeKey());
                                                            @endphp
                                                            <option value="{{ $candidate->getKey() }}">
                                                                {{ $candidateTypeLabel }} #{{ $candidate->getKey() }}
                                                                — {{ $candidate->starts_at?->copy()->setTimezone(filled($candidate->timezone) ? $candidate->timezone : config('app.timezone'))->format('M j, Y g:i A') ?? 'Unscheduled' }}
                                                                — {{ $candidate->external_id }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </label>

                                                <label class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-950">
                                                    <input
                                                        type="checkbox"
                                                        name="confirm_replacement"
                                                        value="1"
                                                        class="mt-0.5 rounded border-amber-400"
                                                        required
                                                    >
                                                    <span>
                                                        I understand this preserves both occurrences, skips obsolete pending messages, and reprovisions each active registration independently.
                                                    </span>
                                                </label>

                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 font-semibold text-white hover:bg-slate-700"
                                                >
                                                    Confirm occurrence replacement
                                                </button>
                                            </form>
                                        </details>
                                    @endif

                                    @if(! $hidden)
                                        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3" data-webinar-remove-control="{{ $webinar->getKey() }}">
                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinars.destroy', $webinar) }}"
                                                onsubmit="return confirm('Remove this webinar event? Engage Core will preserve it when existing history still depends on it.');"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50"
                                                >
                                                    Remove event
                                                </button>
                                            </form>

                                            <p class="mt-2 text-xs leading-5 text-slate-600">
                                                If nothing depends on this event, Engage Core will remove it permanently and remember not to import the same Zoom event again. If registrations, message history, or replacement history depend on it, Engage Core will hide it from new registrations while preserving those references.
                                            </p>
                                        </div>
                                    @endif

                                    @if($finalizationFailures->isNotEmpty())
                                        <div class="mt-3 space-y-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900">
                                            <div>
                                                <p class="font-semibold">Registration setup needs attention</p>
                                                <p class="mt-1 text-red-800">These registrations did not finish correctly and can be retried safely.</p>
                                            </div>

                                            @foreach($finalizationFailures as $failedRegistration)
                                                <div id="webinar-registration-{{ $failedRegistration->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-md bg-white/70 p-2 ring-1 ring-red-200">
                                                    <span>
                                                        <span class="font-semibold">{{ $failedRegistration->contact?->name ?: $failedRegistration->contact?->email ?: 'Registration #'.$failedRegistration->id }}</span>
                                                        — {{ \Illuminate\Support\Str::headline((string) data_get($failedRegistration->meta, 'registration_finalization.failure_reason', 'unknown_failure')) }}
                                                        · {{ (int) data_get($failedRegistration->meta, 'registration_finalization.attempts', 0) }} attempts
                                                    </span>

                                                    <form method="POST" action="{{ route('crm.webinar-registrations.finalization.retry', $failedRegistration) }}">
                                                        @csrf

                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center rounded-md bg-red-700 px-2.5 py-1.5 font-semibold text-white hover:bg-red-600"
                                                        >
                                                            Retry registration
                                                        </button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if($finalizationReconciliations->isNotEmpty())
                                        <div class="mt-3 space-y-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950">
                                            <div>
                                                <p class="font-semibold">Check Zoom before retrying</p>
                                                <p class="mt-1 text-amber-900">Check this registration in Zoom first. Only retry if the person is not already registered there.</p>
                                            </div>

                                            @foreach($finalizationReconciliations as $reconciliationRegistration)
                                                <div id="webinar-registration-{{ $reconciliationRegistration->id }}" class="space-y-3 rounded-md bg-white/80 p-3 ring-1 ring-amber-300">
                                                    <div>
                                                        <span class="font-semibold">{{ $reconciliationRegistration->contact?->name ?: $reconciliationRegistration->contact?->email ?: 'Registration #'.$reconciliationRegistration->id }}</span>
                                                        — {{ \Illuminate\Support\Str::headline((string) data_get($reconciliationRegistration->meta, 'registration_finalization.failure_reason', 'provider_submission_outcome_unknown')) }}
                                                        · {{ \Illuminate\Support\Str::headline((string) data_get($reconciliationRegistration->meta, 'provider_sync.provider', $webinar->providerKey())) }}
                                                    </div>

                                                    <form method="POST" action="{{ route('crm.webinar-registrations.finalization.reconcile', $reconciliationRegistration) }}" class="grid gap-2 rounded-md border border-emerald-200 bg-emerald-50 p-3 sm:grid-cols-2">
                                                        @csrf
                                                        <input type="hidden" name="decision" value="provider_exists">

                                                        <label class="grid gap-1 font-semibold text-emerald-950">
                                                            Zoom registrant ID
                                                            <input
                                                                type="text"
                                                                name="provider_registrant_id"
                                                                maxlength="255"
                                                                required
                                                                class="rounded-md border border-emerald-300 bg-white px-2.5 py-2 text-xs text-slate-900"
                                                            >
                                                        </label>

                                                        <label class="grid gap-1 font-semibold text-emerald-950">
                                                            Zoom join link
                                                            <input
                                                                type="url"
                                                                name="provider_join_url"
                                                                maxlength="2048"
                                                                required
                                                                placeholder="https://..."
                                                                class="rounded-md border border-emerald-300 bg-white px-2.5 py-2 text-xs text-slate-900"
                                                            >
                                                        </label>

                                                        <label class="grid gap-1 font-semibold text-emerald-950 sm:col-span-2">
                                                            Verification notes — optional
                                                            <textarea name="notes" rows="2" maxlength="2000" class="rounded-md border border-emerald-300 bg-white px-2.5 py-2 text-xs text-slate-900"></textarea>
                                                        </label>

                                                        <div class="sm:col-span-2">
                                                            <button type="submit" class="rounded-md bg-emerald-700 px-2.5 py-1.5 font-semibold text-white hover:bg-emerald-600">
                                                                Confirm registration exists in Zoom
                                                            </button>
                                                        </div>
                                                    </form>

                                                    <form method="POST" action="{{ route('crm.webinar-registrations.finalization.reconcile', $reconciliationRegistration) }}" class="grid gap-2 rounded-md border border-amber-300 bg-amber-100 p-3">
                                                        @csrf
                                                        <input type="hidden" name="decision" value="provider_absent">

                                                        <label class="grid gap-1 font-semibold text-amber-950">
                                                            Verification notes — optional
                                                            <textarea name="notes" rows="2" maxlength="2000" class="rounded-md border border-amber-400 bg-white px-2.5 py-2 text-xs text-slate-900"></textarea>
                                                        </label>

                                                        <div>
                                                            <button type="submit" class="rounded-md bg-amber-800 px-2.5 py-1.5 font-semibold text-white hover:bg-amber-700">
                                                                Confirm absent and authorize one resubmission
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if($providerCancellationFailures->isNotEmpty())
                                        <div class="mt-3 space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900">
                                            <p class="font-semibold">Zoom cancellation needs attention</p>

                                            @foreach($providerCancellationFailures as $failedRegistration)
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <span>
                                                        {{ $failedRegistration->contact?->name ?: $failedRegistration->contact?->email ?: 'Registration #'.$failedRegistration->id }}
                                                    </span>

                                                    <form method="POST" action="{{ route('crm.webinar-registrations.provider-cancellation.retry', $failedRegistration) }}">
                                                        @csrf

                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center rounded-md bg-red-700 px-2.5 py-1.5 font-semibold text-white hover:bg-red-600"
                                                        >
                                                            Retry Zoom cancellation
                                                        </button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if($followUpFailures->isNotEmpty())
                                        <div class="mt-3 space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900">
                                            <p class="font-semibold">Post-webinar follow-up needs attention</p>

                                            @foreach($followUpFailures as $failedRegistration)
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <span>
                                                        {{ $failedRegistration->contact?->name ?: $failedRegistration->contact?->email ?: 'Registration #'.$failedRegistration->id }}
                                                        — {{ \Illuminate\Support\Str::headline((string) data_get($failedRegistration->meta, 'post_event_follow_up.failure_reason', 'unknown_failure')) }}
                                                    </span>

                                                    <form method="POST" action="{{ route('crm.webinar-registrations.follow-up.retry', $failedRegistration) }}">
                                                        @csrf

                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center rounded-md bg-red-700 px-2.5 py-1.5 font-semibold text-white hover:bg-red-600"
                                                        >
                                                            Retry follow-up planning
                                                        </button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    <div>{{ $webinar->webinarSeries?->title ?? '—' }}</div>
                                    <div class="mt-1 text-xs font-semibold text-indigo-700">
                                        Zoom {{ $eventTypeLabel }}
                                    </div>
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

                                                <button
                                                    type="button"
                                                    data-webinar-get-links="{{ $webinar->getKey() }}"
                                                    x-on:click="openLinksModal({{ $webinar->getKey() }})"
                                                    class="inline-flex items-center rounded-md border border-sky-300 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800 hover:bg-sky-100"
                                                >
                                                    Get Links
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

            <details id="advanced-webinar-setup" class="rounded-3xl border border-slate-200 bg-slate-50 p-4 shadow-sm sm:p-5">
                <summary class="cursor-pointer list-none">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">Operator setup</p>
                            <h2 class="mt-1 text-lg font-black text-slate-950">Manage webinar setup</h2>
                            <p class="mt-1 text-sm text-slate-600">Add webinar series, refresh dates from Zoom, choose message plans, and open testing tools when they are available.</p>
                        </div>
                        <span class="text-sm font-extrabold text-slate-700">Open setup</span>
                    </div>
                </summary>

                @if($webinarDevEnabled ?? $webinarSmokeEnabled ?? false)
                    <div class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                        <p class="font-semibold">Webinar testing tools are available in this environment.</p>
                        <p class="mt-1 text-indigo-800">Testing buttons on event rows let you rehearse messages, joins, attendance outcomes, replay links, and follow-up behavior without changing the normal workspace.</p>
                    </div>
                @endif

                <div class="mt-5 grid gap-6 xl:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                        <h2 class="text-base font-semibold text-slate-900">
                            Add a webinar series
                        </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.store') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="title" class="block text-sm font-medium text-slate-700">
                                Series name
                            </label>

                            <input
                                id="title"
                                name="title"
                                type="text"
                                value="{{ old('title') }}"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                placeholder="Exact Zoom event series title"
                                required
                            >

                            @error('title')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label for="provider_event_type" class="block text-sm font-medium text-slate-700">
                                Zoom Event Type
                            </label>

                            <select
                                id="provider_event_type"
                                name="provider_event_type"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                required
                            >
                                <option value="">Select an event type</option>
                                @foreach($providerEventTypeOptions as $eventType => $eventTypeLabel)
                                    <option value="{{ $eventType }}" @selected(old('provider_event_type') === $eventType)>
                                        Zoom {{ $eventTypeLabel }}
                                    </option>
                                @endforeach
                            </select>

                            @error('provider_event_type')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <p class="text-xs leading-5 text-slate-500">
                            Choose whether this series uses Zoom Webinar or Zoom Meeting. This affects future schedule refreshes only; existing events keep their recorded type.
                        </p>

                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 sm:w-auto"
                        >
                            Add a webinar series
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <h2 class="text-base font-semibold text-slate-900">
                        Refresh webinar dates
                    </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.sync') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="webinar_series_id" class="block text-sm font-medium text-slate-700">
                                Which series?
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
                                        — Zoom {{ $providerEventTypeOptions[$seriesItem->providerEventTypeKey()] ?? \Illuminate\Support\Str::headline($seriesItem->providerEventTypeKey()) }}
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
                            class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 sm:w-auto"
                        >
                            Refresh from Zoom
                        </button>
                    </form>
                </div>

                @if($series->isNotEmpty())
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                        <h2 class="text-base font-semibold text-slate-900">
                            Manage existing series
                        </h2>

                        <div class="mt-4 space-y-2">
                            @foreach($series as $seriesItem)
                                <div class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-700">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                                        <div>
                                            <p class="font-semibold text-slate-900">{{ $seriesItem->title }}</p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                                <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                                    Zoom {{ $providerEventTypeOptions[$seriesItem->providerEventTypeKey()] ?? \Illuminate\Support\Str::headline($seriesItem->providerEventTypeKey()) }}
                                                </span>
                                                <span class="text-slate-500">
                                                    Message plan: {{ $seriesItem->webinarScheduleProfile?->name ?? (($scheduleProfiles ?? collect())->firstWhere('is_default', true)?->name ?? 'Default') }}
                                                </span>
                                            </div>
                                        </div>

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

                                    @php
                                        $hasCustomMessageChains = $seriesItem->messageChainBindings
                                            ->where('is_active', true)
                                            ->isNotEmpty();
                                    @endphp

                                    <div class="mt-3 space-y-3 rounded-xl border border-slate-200 bg-white p-3" data-series-zoom-setup="{{ $seriesItem->getKey() }}">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Zoom setup</p>
                                            <p class="mt-1 text-xs text-slate-600">Choose whether future refreshes look for a Zoom Meeting or Zoom Webinar. Existing events keep their recorded type.</p>
                                        </div>

                                        <form
                                            method="POST"
                                            action="{{ route('crm.webinar-series.provider-event-type.update', $seriesItem) }}"
                                            class="space-y-2"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <label class="grid gap-1 text-xs font-semibold text-slate-800">
                                                Zoom event type
                                                <div class="flex flex-col gap-2 sm:flex-row">
                                                    <select
                                                        name="provider_event_type"
                                                        class="w-full min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                                        aria-label="Zoom event type for {{ $seriesItem->title }}"
                                                        required
                                                    >
                                                        @foreach($providerEventTypeOptions as $eventType => $eventTypeLabel)
                                                            <option
                                                                value="{{ $eventType }}"
                                                                @selected($seriesItem->providerEventTypeKey() === $eventType)
                                                            >
                                                                Zoom {{ $eventTypeLabel }}
                                                            </option>
                                                        @endforeach
                                                    </select>

                                                    <button
                                                        type="submit"
                                                        class="w-full rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 sm:w-auto"
                                                    >
                                                        Save Zoom type
                                                    </button>
                                                </div>
                                            </label>
                                        </form>
                                    </div>

                                    <div class="mt-3 space-y-3 rounded-xl border border-indigo-100 bg-indigo-50/40 p-3" data-series-message-plan="{{ $seriesItem->getKey() }}">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase tracking-wide text-indigo-700">Messages</p>
                                            <p class="mt-1 text-xs text-slate-600">The message plan controls which confirmations, reminders, and follow-ups run and when. Message content controls what those messages say.</p>
                                        </div>

                                        @if(($scheduleProfiles ?? collect())->isNotEmpty())
                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinar-series.schedule-profile.update', $seriesItem) }}"
                                                class="space-y-2"
                                            >
                                                @csrf
                                                @method('PATCH')

                                                <label class="grid gap-1 text-xs font-semibold text-slate-800">
                                                    Message plan
                                                    <div class="flex flex-col gap-2 sm:flex-row">
                                                        <select
                                                            name="webinar_schedule_profile_id"
                                                            class="w-full min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                                            aria-label="Message plan for {{ $seriesItem->title }}"
                                                        >
                                                            <option value="">Use default message plan</option>
                                                            @foreach($scheduleProfiles as $scheduleProfile)
                                                                <option
                                                                    value="{{ $scheduleProfile->getKey() }}"
                                                                    @selected((int) $seriesItem->webinar_schedule_profile_id === (int) $scheduleProfile->getKey())
                                                                >
                                                                    {{ $scheduleProfile->name }}{{ $scheduleProfile->is_default ? ' (default)' : '' }}
                                                                </option>
                                                            @endforeach
                                                        </select>

                                                        <button
                                                            type="submit"
                                                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                                                        >
                                                            Save message plan
                                                        </button>
                                                    </div>
                                                </label>
                                            </form>
                                        @endif

                                        <div class="flex flex-col items-start gap-2 rounded-xl border border-indigo-100 bg-white px-3 py-2 sm:flex-row sm:items-center sm:justify-between" data-series-message-content="{{ $seriesItem->getKey() }}">
                                            <div>
                                                <p class="text-xs font-bold text-slate-900">Message content</p>
                                                <p class="mt-0.5 text-[11px] text-slate-500">
                                                    {{ $hasCustomMessageChains ? 'This series uses customized message copy.' : 'This series uses the default message copy.' }}
                                                </p>
                                            </div>

                                            <a
                                                href="{{ route('crm.webinar-series.message-chains.show', $seriesItem) }}"
                                                class="text-xs font-extrabold text-indigo-700 hover:text-indigo-900"
                                            >
                                                {{ $hasCustomMessageChains ? 'Edit message content' : 'Customize message content' }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                </div>
            </details>
        </div>
    </div>
</x-layouts.crm>