<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6" data-webinar-type-directory>
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if (session('zoom_sync_error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('zoom_sync_error') }}
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Webinar types</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ $showArchivedTypes ? 'Archived webinar types' : 'Choose the class or event you want to work with' }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        @if($showArchivedTypes)
                            Archived types are unavailable for new public registrations and normal Zoom syncing, but their sessions and history stay available here.
                        @else
                            A webinar type is the recurring class or event. Open one to get its registration links, see upcoming and past sessions, and recover anything that was removed.
                        @endif
                    </p>
                </div>

                @if(! $showArchivedTypes)
                    <details class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 lg:max-w-sm">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-900">Add a webinar type</summary>

                    <form method="POST" action="{{ route('crm.webinar-series.store') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="title" class="block text-sm font-medium text-slate-700">Name</label>
                            <input
                                id="title"
                                name="title"
                                type="text"
                                value="{{ old('title') }}"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                placeholder="Homebuyer Game Plan"
                                required
                            >
                            @error('title')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="provider_event_type" class="block text-sm font-medium text-slate-700">Zoom event type</label>
                            <select
                                id="provider_event_type"
                                name="provider_event_type"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                required
                            >
                                <option value="">Choose one</option>
                                @foreach($providerEventTypeOptions as $eventType => $eventTypeLabel)
                                    <option value="{{ $eventType }}" @selected(old('provider_event_type') === $eventType)>
                                        {{ $eventTypeLabel }}
                                    </option>
                                @endforeach
                            </select>
                            @error('provider_event_type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            Add webinar type
                        </button>
                    </form>
                    </details>
                @else
                    <a
                        href="{{ route('crm.webinar-series.index') }}"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                    >
                        Back to active webinar types
                    </a>
                @endif
            </div>
        </section>

        @if(! $showArchivedTypes && $archivedTypeCount > 0)
            <div>
                <a href="{{ route('crm.webinar-series.index', ['archived_types' => 1]) }}" class="text-sm font-semibold text-slate-600 underline">
                    Archived webinar types ({{ number_format($archivedTypeCount) }})
                </a>
            </div>
        @endif

        @if(($attentionCount ?? 0) > 0)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4" data-webinar-directory-attention>
                <p class="text-sm font-semibold text-amber-950">
                    {{ number_format($attentionCount) }} webinar {{ \Illuminate\Support\Str::plural('item', $attentionCount) }} may need attention.
                </p>
                <p class="mt-1 text-sm text-amber-900">
                    Open the webinar type involved to see missing Zoom sessions, registration recovery, or post-event review details.
                </p>
            </section>
        @endif

        @if($showAttention)
            <section class="rounded-2xl border border-red-200 bg-white p-5 shadow-sm" data-webinar-registration-recovery>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-red-700">Registration recovery</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">Registrations that need a decision</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        These sessions are shown even when they have already ended because a registration still needs operator review.
                    </p>
                </div>

                <div class="mt-5 space-y-4">
                    @forelse($webinars as $attentionWebinar)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-950">{{ $attentionWebinar->title }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">
                                        {{ $attentionWebinar->starts_at?->copy()->setTimezone($attentionWebinar->timezone)->format('M j, Y · g:i A T') ?? 'Start time unavailable' }}
                                    </p>
                                </div>

                                <a
                                    href="{{ route('crm.webinars.show', $attentionWebinar) }}"
                                    class="text-sm font-semibold text-slate-700 underline"
                                >
                                    Session details
                                </a>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach($attentionWebinar->registrations as $attentionRegistration)
                                    @if(
                                        data_get($attentionRegistration->meta, 'registration_finalization.status') === 'failed'
                                        && data_get($attentionRegistration->meta, 'provider_sync.status') !== 'reconciliation_required'
                                    )
                                        <div
                                            id="webinar-registration-{{ $attentionRegistration->id }}"
                                            class="flex flex-col gap-3 rounded-lg border border-red-200 bg-red-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div class="text-sm text-red-950">
                                                <span class="font-semibold">
                                                    {{ $attentionRegistration->contact?->name ?: $attentionRegistration->contact?->email ?: 'Registration #'.$attentionRegistration->id }}
                                                </span>
                                                <span class="text-red-800">
                                                    — {{ \Illuminate\Support\Str::headline((string) data_get($attentionRegistration->meta, 'registration_finalization.failure_reason', 'unknown_failure')) }}
                                                </span>
                                            </div>

                                            <form method="POST" action="{{ route('crm.webinar-registrations.finalization.retry', $attentionRegistration) }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center justify-center rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-600"
                                                >
                                                    Retry registration
                                                </button>
                                            </form>
                                        </div>
                                    @elseif(
                                        data_get($attentionRegistration->meta, 'registration_finalization.status') === 'reconciliation_required'
                                        || data_get($attentionRegistration->meta, 'provider_sync.status') === 'reconciliation_required'
                                    )
                                        <div
                                            id="webinar-registration-{{ $attentionRegistration->id }}"
                                            class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3"
                                        >
                                            <div class="text-sm text-amber-950">
                                                <span class="font-semibold">
                                                    {{ $attentionRegistration->contact?->name ?: $attentionRegistration->contact?->email ?: 'Registration #'.$attentionRegistration->id }}
                                                </span>
                                                <span class="text-amber-900">
                                                    — Check Zoom before allowing another provider submission.
                                                </span>
                                            </div>

                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinar-registrations.finalization.reconcile', $attentionRegistration) }}"
                                                class="grid gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 sm:grid-cols-2"
                                            >
                                                @csrf
                                                <input type="hidden" name="decision" value="provider_exists">

                                                <label class="grid gap-1 text-xs font-semibold text-emerald-950">
                                                    Zoom registrant ID
                                                    <input
                                                        type="text"
                                                        name="provider_registrant_id"
                                                        maxlength="255"
                                                        required
                                                        class="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm text-slate-900"
                                                    >
                                                </label>

                                                <label class="grid gap-1 text-xs font-semibold text-emerald-950">
                                                    Zoom join link
                                                    <input
                                                        type="url"
                                                        name="provider_join_url"
                                                        maxlength="2048"
                                                        required
                                                        placeholder="https://..."
                                                        class="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm text-slate-900"
                                                    >
                                                </label>

                                                <label class="grid gap-1 text-xs font-semibold text-emerald-950 sm:col-span-2">
                                                    Verification notes — optional
                                                    <textarea
                                                        name="notes"
                                                        rows="2"
                                                        maxlength="2000"
                                                        class="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm text-slate-900"
                                                    ></textarea>
                                                </label>

                                                <div class="sm:col-span-2">
                                                    <button
                                                        type="submit"
                                                        class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-600"
                                                    >
                                                        Confirm registration exists in Zoom
                                                    </button>
                                                </div>
                                            </form>

                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinar-registrations.finalization.reconcile', $attentionRegistration) }}"
                                                class="grid gap-3 rounded-lg border border-amber-300 bg-amber-100 p-3"
                                            >
                                                @csrf
                                                <input type="hidden" name="decision" value="provider_absent">

                                                <label class="grid gap-1 text-xs font-semibold text-amber-950">
                                                    Verification notes — optional
                                                    <textarea
                                                        name="notes"
                                                        rows="2"
                                                        maxlength="2000"
                                                        class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-sm text-slate-900"
                                                    ></textarea>
                                                </label>

                                                <div>
                                                    <button
                                                        type="submit"
                                                        class="rounded-lg bg-amber-800 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                                                    >
                                                        Confirm absent and authorize one resubmission
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">
                            No webinar registration recovery is waiting right now.
                        </div>
                    @endforelse
                </div>

                <div class="mt-4">
                    <a href="{{ route('crm.webinar-series.index') }}" class="text-sm font-semibold text-slate-700 underline">
                        Back to Webinar Types
                    </a>
                </div>
            </section>
        @endif

        @if($showArchived)
            <section
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                data-webinar-session-history-recovery
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">
                            Session history
                        </p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-950">
                            Past sessions and recovery
                        </h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                            Review historical webinar sessions and retry any provider cancellation or post-webinar follow-up work that did not finish successfully.
                        </p>
                    </div>

                    <a
                        href="{{ route('crm.webinar-series.index') }}"
                        class="text-sm font-semibold text-slate-700 underline"
                    >
                        Back to Webinar Types
                    </a>
                </div>

                <div class="mt-5 space-y-4">
                    @forelse($archivedRecoveryRows as $recoveryRow)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-950">
                                        {{ $recoveryRow['webinar']->title }}
                                    </h3>

                                    <p class="mt-1 text-sm text-slate-600">
                                        {{ $recoveryRow['webinar']->starts_at?->copy()->setTimezone($recoveryRow['webinar']->timezone)->format('M j, Y · g:i A T') ?? 'Start time unavailable' }}
                                    </p>

                                    @if($recoveryRow['webinar']->webinarSeries)
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $recoveryRow['webinar']->webinarSeries->title }}
                                        </p>
                                    @endif
                                </div>

                                <a
                                    href="{{ route('crm.webinars.show', $recoveryRow['webinar']) }}"
                                    class="text-sm font-semibold text-slate-700 underline"
                                >
                                    Session details
                                </a>
                            </div>

                            @if($recoveryRow['provider_cancellation_failure_count'] > 0)
                                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3">
                                    <p class="text-xs font-semibold text-red-900">
                                        {{ $recoveryRow['provider_cancellation_failure_count'] }}
                                        {{ $recoveryRow['provider_cancellation_failure_count'] === 1 ? 'Zoom cancellation failure' : 'Zoom cancellation failures' }}
                                    </p>

                                    <div class="mt-3 space-y-2">
                                        @foreach($recoveryRow['provider_cancellation_failures'] as $failedRegistration)
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <span class="text-xs text-red-900">
                                                    {{ $failedRegistration->contact?->name ?: $failedRegistration->contact?->email ?: 'Registration #'.$failedRegistration->id }}
                                                </span>

                                                <form
                                                    method="POST"
                                                    action="{{ route('crm.webinar-registrations.provider-cancellation.retry', $failedRegistration) }}"
                                                >
                                                    @csrf

                                                    <button
                                                        type="submit"
                                                        class="inline-flex items-center justify-center rounded-lg bg-red-700 px-3 py-2 text-xs font-semibold text-white hover:bg-red-600"
                                                    >
                                                        Retry Zoom cancellation
                                                    </button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if($recoveryRow['follow_up_failure_count'] > 0)
                                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3">
                                    <p class="text-xs font-semibold text-red-900">
                                        {{ $recoveryRow['follow_up_failure_count'] }}
                                        follow-up planning
                                        {{ $recoveryRow['follow_up_failure_count'] === 1 ? 'failure' : 'failures' }}
                                    </p>

                                    <div class="mt-3 space-y-2">
                                        @foreach($recoveryRow['follow_up_failures'] as $failedRegistration)
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <span class="text-xs text-red-900">
                                                    {{ $failedRegistration->contact?->name ?: $failedRegistration->contact?->email ?: 'Registration #'.$failedRegistration->id }}
                                                    —
                                                    {{ \Illuminate\Support\Str::headline((string) data_get($failedRegistration->meta, 'post_event_follow_up.failure_reason', 'unknown_failure')) }}
                                                </span>

                                                <form
                                                    method="POST"
                                                    action="{{ route('crm.webinar-registrations.follow-up.retry', $failedRegistration) }}"
                                                >
                                                    @csrf

                                                    <button
                                                        type="submit"
                                                        class="inline-flex items-center justify-center rounded-lg bg-red-700 px-3 py-2 text-xs font-semibold text-white hover:bg-red-600"
                                                    >
                                                        Retry follow-up planning
                                                    </button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if(
                                $recoveryRow['provider_cancellation_failure_count'] === 0
                                && $recoveryRow['follow_up_failure_count'] === 0
                            )
                                <p class="mt-4 text-xs text-slate-500">
                                    No provider cancellation or follow-up recovery is waiting for this session.
                                </p>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">
                            No historical webinar sessions are available.
                        </div>
                    @endforelse
                </div>
            </section>
        @endif

        <section class="grid gap-4 xl:grid-cols-2">
            @forelse($series as $seriesItem)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-webinar-type="{{ $seriesItem->getKey() }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">{{ $seriesItem->title }}</h2>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                                    {{ $providerEventTypeOptions[$seriesItem->providerEventTypeKey()] ?? $seriesItem->providerEventTypeKey() }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $seriesItem->status === 'active' ? 'Active' : 'Archived' }}
                            </p>
                        </div>

                        <a
                            href="{{ route('crm.webinar-series.show', $seriesItem) }}"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        >
                            Open
                        </a>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <div class="text-lg font-semibold text-slate-950">{{ (int) $seriesItem->upcoming_sessions_count }}</div>
                            <div class="text-xs text-slate-500">Upcoming</div>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <div class="text-lg font-semibold text-slate-950">{{ (int) $seriesItem->past_sessions_count }}</div>
                            <div class="text-xs text-slate-500">Past</div>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <div class="text-lg font-semibold text-slate-950">
                                {{ (int) $seriesItem->removed_sessions_count + (int) $seriesItem->suppressed_sessions_count }}
                            </div>
                            <div class="text-xs text-slate-500">Removed</div>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <div class="text-lg font-semibold text-slate-950">{{ $seriesItem->webinars->first()?->registrations_count ?? '—' }}</div>
                            <div class="text-xs text-slate-500">Next signups</div>
                        </div>
                    </div>

                    @if($seriesItem->webinars->first())
                        <div class="mt-4 rounded-xl border border-slate-200 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Next session</p>
                            <div class="mt-1 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $seriesItem->webinars->first()->title }}</p>
                                    <p class="text-sm text-slate-600">
                                        {{ $seriesItem->webinars->first()->starts_at?->copy()->setTimezone($seriesItem->webinars->first()->timezone)->format('M j, Y · g:i A T') }}
                                    </p>
                                </div>
                                <a href="{{ route('crm.webinars.show', $seriesItem->webinars->first()) }}" class="text-sm font-semibold text-slate-700 underline">
                                    Session details
                                </a>
                            </div>
                        </div>
                    @else
                        <p class="mt-4 text-sm text-slate-500">No upcoming synced session is currently available.</p>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                        @if(! $showArchivedTypes)
                            <form method="POST" action="{{ route('crm.webinar-series.sync') }}">
                                @csrf
                                <input type="hidden" name="webinar_series_id" value="{{ $seriesItem->getKey() }}">
                                <button type="submit" class="text-sm font-semibold text-slate-700 underline">
                                    Sync from Zoom
                                </button>
                            </form>

                            <a href="{{ route('crm.webinar-series.show', $seriesItem) }}#links" class="text-sm font-semibold text-slate-700 underline">
                                Get links
                            </a>
                        @endif

                        @if(((int) $seriesItem->removed_sessions_count + (int) $seriesItem->suppressed_sessions_count) > 0)
                            <a href="{{ route('crm.webinar-series.show', $seriesItem) }}#removed" class="text-sm font-semibold text-amber-700 underline">
                                Review removed sessions
                            </a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center xl:col-span-2">
                    <p class="text-sm font-semibold text-slate-800">{{ $showArchivedTypes ? 'No archived webinar types.' : 'No webinar types yet.' }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $showArchivedTypes ? 'Archived webinar types will appear here when you remove a type that has history.' : 'Add the first recurring class or event above, then sync its sessions from Zoom.' }}
                    </p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>