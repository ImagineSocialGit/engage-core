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
                <p class="font-medium">Active webinar event conflicts detected.</p>

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
                <p class="font-medium">Missing provider events preserved (not deleted).</p>

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

        @if(function_exists('module_enabled') && module_enabled('messaging') && \Illuminate\Support\Facades\Route::has('crm.webinars.message-templates.index'))
            <div class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-semibold text-slate-950">Webinar messages</p>
                        <p class="mt-1 text-slate-600">Review the templates used for confirmations, reminders, waitlist alerts, and replay follow-ups before sharing registration links.</p>
                    </div>
                    <a
                        href="{{ route('crm.webinars.message-templates.index') }}"
                        class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-xs font-extrabold text-slate-700 transition hover:bg-slate-50"
                    >
                        Choose templates
                    </a>
                </div>
            </div>
        @endif

        @if($webinarDevEnabled ?? $webinarSmokeEnabled ?? false)
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                <p class="font-semibold">Webinar Event Testing Tools Enabled</p>
                <p class="mt-1 text-indigo-800">
                    Use the Testing button on any event to open dev/staging-only controls for confirmations, reminders, join simulation, FlowRoute events, replay URLs, and post-webinar follow-ups.
                </p>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 px-12 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">
                        {{ $showAttention ? 'Registration Recovery' : ($showArchived ? 'All Events' : 'Upcoming Events') }}
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
                            Archived
                        </a>
                    </div>
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
                                $registrationUrl = filled($webinar->webinarSeries?->slug)
                                    ? route('webinar.show', [
                                        'seriesSlug' => $webinar->webinarSeries->slug,
                                    ])
                                    : null;
                                $eventTypeLabel = $providerEventTypeOptions[$webinar->providerEventTypeKey()]
                                    ?? \Illuminate\Support\Str::headline($webinar->providerEventTypeKey());
                                $replacementCandidates = $replacementCandidatesBySourceId[$webinar->getKey()]
                                    ?? collect();
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

                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-medium text-slate-900">
                                    {{ $webinar->title }}

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                            Zoom {{ $eventTypeLabel }}
                                        </span>

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
                                                {{ $finalizationFailures->count() }} registration finalization {{ $finalizationFailures->count() === 1 ? 'failure' : 'failures' }}
                                            </span>
                                        @endif

                                        @if($finalizationReconciliations->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $finalizationReconciliations->count() }} provider {{ $finalizationReconciliations->count() === 1 ? 'verification' : 'verifications' }} required
                                            </span>
                                        @endif

                                        @if($finalizationsPending->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $finalizationsPending->count() }} registration {{ $finalizationsPending->count() === 1 ? 'finalization' : 'finalizations' }} pending
                                            </span>
                                        @endif

                                        @if($providerCancellationFailures->isNotEmpty())
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">
                                                {{ $providerCancellationFailures->count() }} provider cancellation {{ $providerCancellationFailures->count() === 1 ? 'failure' : 'failures' }}
                                            </span>
                                        @endif

                                        @if($providerCancellationsPending->isNotEmpty())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">
                                                {{ $providerCancellationsPending->count() }} provider {{ $providerCancellationsPending->count() === 1 ? 'cancellation' : 'cancellations' }} pending
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
                                                                — {{ $candidate->starts_at?->copy()->setTimezone($candidate->timezone)->format('M j, Y g:i A') ?? 'Unscheduled' }}
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

                                    @if($finalizationFailures->isNotEmpty())
                                        <div class="mt-3 space-y-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900">
                                            <div>
                                                <p class="font-semibold">Registration finalization needs attention</p>
                                                <p class="mt-1 text-red-800">These failures are safe to retry because no ambiguous provider submission remains unresolved.</p>
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
                                                            Retry finalization
                                                        </button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if($finalizationReconciliations->isNotEmpty())
                                        <div class="mt-3 space-y-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950">
                                            <div>
                                                <p class="font-semibold">Provider verification required</p>
                                                <p class="mt-1 text-amber-900">Check the provider’s registrant list first. Do not authorize resubmission until you have confirmed the registration is absent.</p>
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
                                                            Provider registrant ID
                                                            <input
                                                                type="text"
                                                                name="provider_registrant_id"
                                                                maxlength="255"
                                                                required
                                                                class="rounded-md border border-emerald-300 bg-white px-2.5 py-2 text-xs text-slate-900"
                                                            >
                                                        </label>

                                                        <label class="grid gap-1 font-semibold text-emerald-950">
                                                            Provider join URL
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
                                                                Confirm provider registration exists
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
                                            <p class="font-semibold">Provider cancellation needs attention</p>

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
                                                            Retry provider cancellation
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
                            This selects the provider adapter used for future synchronization. Existing occurrences are never retyped automatically.
                        </p>

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
                                <div class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-700">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-slate-900">{{ $seriesItem->title }}</p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                                <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                                    Zoom {{ $providerEventTypeOptions[$seriesItem->providerEventTypeKey()] ?? \Illuminate\Support\Str::headline($seriesItem->providerEventTypeKey()) }}
                                                </span>
                                                <span class="text-slate-500">
                                                    Schedule: {{ $seriesItem->webinarScheduleProfile?->name ?? (($scheduleProfiles ?? collect())->firstWhere('is_default', true)?->name ?? 'Default profile') }}
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

                                    <form
                                        method="POST"
                                        action="{{ route('crm.webinar-series.provider-event-type.update', $seriesItem) }}"
                                        class="mt-3 space-y-2"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <div class="flex gap-2">
                                            <select
                                                name="provider_event_type"
                                                class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
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
                                                class="rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                            >
                                                Save type
                                            </button>
                                        </div>

                                        <p class="text-[11px] leading-4 text-slate-500">
                                            Changes future provider sync only. Existing occurrences remain historically typed.
                                        </p>
                                    </form>

                                    @if(($scheduleProfiles ?? collect())->isNotEmpty())
                                        <form
                                            method="POST"
                                            action="{{ route('crm.webinar-series.schedule-profile.update', $seriesItem) }}"
                                            class="mt-3 flex gap-2"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <select
                                                name="webinar_schedule_profile_id"
                                                class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                                aria-label="Webinar schedule profile for {{ $seriesItem->title }}"
                                            >
                                                <option value="">Use default profile</option>
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
                                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                            >
                                                Save
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.crm>