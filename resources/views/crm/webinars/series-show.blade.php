<x-layouts.crm :title="$title" :heading="$heading">
    <div
        class="space-y-6"
        data-webinar-type-detail="{{ $series->getKey() }}"
        x-data="{
            copied: null,
            copyFailed: false,
            async copyValue(value, key) {
                if (!value) return;

                this.copyFailed = false;

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

                        if (!document.execCommand('copy')) {
                            throw new Error('copy_failed');
                        }

                        textarea.remove();
                    }

                    this.copied = key;
                    setTimeout(() => {
                        if (this.copied === key) this.copied = null;
                    }, 1500);
                } catch (error) {
                    this.copyFailed = true;
                    this.copied = null;
                }
            }
        }"
    >
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('crm.webinar-series.index') }}" class="font-semibold text-slate-600 underline">Webinar types</a>
            <span class="text-slate-400">/</span>
            <span class="text-slate-700">{{ $series->title }}</span>
        </div>

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

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Webinar type</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ $series->title }}</h1>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm text-slate-600">
                        <span>{{ $providerEventTypeLabel }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ $series->status === 'active' ? 'Active' : 'Archived' }}</span>
                        @if($messageProfile)
                            <span aria-hidden="true">·</span>
                            <span>Message plan: {{ $messageProfile->name }}</span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if($series->status === 'active')
                        @if(function_exists('module_enabled') && module_enabled('messaging'))
                            <a
                                href="{{ route('crm.webinar-series.message-chains.show', $series) }}"
                                class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Edit messages
                            </a>
                        @endif
                        <a
                            href="#links"
                            class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        >
                            Get links
                        </a>
                    @else
                        <form method="POST" action="{{ route('crm.webinar-series.restore', $series) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                Restore webinar type
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ $upcomingWebinars->count() }}</div>
                    <div class="text-xs text-slate-500">Upcoming</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ $historyWebinars->count() }}</div>
                    <div class="text-xs text-slate-500">Past</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ $providerMissingOccurrences->count() }}</div>
                    <div class="text-xs text-slate-500">Zoom attention</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ $removedWebinars->count() + $suppressedOccurrences->count() }}</div>
                    <div class="text-xs text-slate-500">Removed</div>
                </div>
            </div>
        </section>

        @if($series->status !== 'active')
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5" data-webinar-type-archived>
                <h2 class="text-lg font-semibold text-amber-950">This webinar type is archived</h2>
                <p class="mt-1 text-sm leading-6 text-amber-900">
                    It is unavailable for new public registrations and normal Zoom syncing. Existing registrations, sessions, attendance history, and already-scheduled communication are preserved.
                </p>
            </section>
        @endif

        @if($series->status === 'active')
        <section id="links" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-links>
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Links</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Registration page</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    This is the public link for this webinar type. It always points people to the correct available session.
                </p>
            </div>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <input
                    type="text"
                    readonly
                    value="{{ $registrationUrl }}"
                    class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
                >
                <button
                    type="button"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                    x-on:click="copyValue(@js($registrationUrl), 'registration')"
                >
                    <span x-show="copied !== 'registration'">Copy link</span>
                    <span x-show="copied === 'registration'" x-cloak>Copied</span>
                </button>
            </div>

            @if($paidAdTrackingPlatforms !== [])
                <details class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-webinar-ad-reporting-links>
                    <summary class="cursor-pointer text-sm font-semibold text-slate-900">Get ad tracking setup</summary>

                    <div class="mt-4 space-y-4">
                        @foreach($paidAdTrackingPlatforms as $platformKey => $platform)
                            <div class="rounded-xl border border-slate-200 bg-white p-4">
                                <h3 class="text-sm font-semibold text-slate-900">{{ $platform['label'] ?? ucfirst($platformKey) }}</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $platform['instructions'] ?? '' }}</p>

                                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <input
                                        type="text"
                                        readonly
                                        value="{{ $platform['parameters'] ?? '' }}"
                                        class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-700"
                                    >
                                    <button
                                        type="button"
                                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                        x-on:click="copyValue(@js($platform['parameters'] ?? ''), @js('tracking-'.$platformKey))"
                                    >
                                        <span x-show="copied !== @js('tracking-'.$platformKey)">Copy tracking</span>
                                        <span x-show="copied === @js('tracking-'.$platformKey)" x-cloak>Copied</span>
                                    </button>
                                </div>

                                @if(($platform['notes'] ?? []) !== [])
                                    <ul class="mt-3 list-disc space-y-1 pl-5 text-xs leading-5 text-slate-500">
                                        @foreach($platform['notes'] as $note)
                                            <li>{{ $note }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            <p x-show="copyFailed" x-cloak class="mt-3 text-sm text-red-600">
                Copy failed. Select the text above and copy it manually.
            </p>
        </section>
        @endif

        @if($providerMissingOccurrences->isNotEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5" data-webinar-type-provider-attention>
                <h2 class="text-lg font-semibold text-amber-950">Zoom sessions that need attention</h2>
                <p class="mt-1 text-sm text-amber-900">
                    These sessions were previously known to Engage Core but were not found in the latest authoritative Zoom schedule.
                </p>

                <div class="mt-4 space-y-3">
                    @foreach($providerMissingOccurrences as $missing)
                        <div class="rounded-xl border border-amber-200 bg-white p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $missing->title }}</p>
                                    <p class="text-sm text-slate-600">
                                        {{ $missing->starts_at?->copy()->setTimezone($missing->timezone)->format('M j, Y · g:i A T') ?? 'Date unavailable' }}
                                        · {{ (int) $missing->registrations_count }} registrations
                                    </p>
                                </div>
                                <a href="{{ route('crm.webinars.show', $missing) }}" class="text-sm font-semibold text-slate-700 underline">Open session</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-upcoming>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Upcoming sessions</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Next dates</h2>
            </div>

            <div class="mt-4 divide-y divide-slate-100">
                @forelse($upcomingWebinars as $session)
                    <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $session->title }}</p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ $session->starts_at?->copy()->setTimezone($session->timezone)->format('M j, Y · g:i A T') }}
                                · {{ (int) $session->registrations_count }} registrations
                            </p>
                        </div>
                        <a
                            href="{{ route('crm.webinars.show', $session) }}"
                            class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                        >
                            View session
                        </a>
                    </div>
                @empty
                    <p class="py-4 text-sm text-slate-500">No upcoming synced sessions.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-history>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Past sessions</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">History</h2>
            </div>

            <div class="mt-4 divide-y divide-slate-100">
                @forelse($historyWebinars as $session)
                    <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $session->title }}</p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ $session->starts_at?->copy()->setTimezone($session->timezone)->format('M j, Y · g:i A T') ?? 'Date unavailable' }}
                                · {{ (int) $session->registrations_count }} registrations
                            </p>
                        </div>
                        <a href="{{ route('crm.webinars.show', $session) }}" class="text-sm font-semibold text-slate-700 underline">View attendance and details</a>
                    </div>
                @empty
                    <p class="py-4 text-sm text-slate-500">No past sessions yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-message-plan>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Messages</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Message plan</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $messageProfile?->name ?? 'No active message plan' }}
                        · {{ (int) ($messageReview['message_count'] ?? 0) }} messages
                    </p>
                </div>
                @if(function_exists('module_enabled') && module_enabled('messaging'))
                    <a href="{{ route('crm.webinar-series.message-chains.show', $series) }}" class="text-sm font-semibold text-slate-700 underline">
                        Review message content
                    </a>
                @endif
            </div>
        </section>

        <section id="removed" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-removed>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Removed sessions</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Sessions you intentionally removed</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Removed sessions stay here so they do not disappear without explanation. Sessions with history are hidden; empty Zoom sessions are kept out by a suppression record.
                </p>
            </div>

            <div class="mt-5 space-y-3">
                @foreach($removedWebinars as $removed)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-removed-webinar-session="{{ $removed->getKey() }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $removed->title }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $removed->starts_at?->copy()->setTimezone($removed->timezone)->format('M j, Y · g:i A T') ?? 'Date unavailable' }}
                                    · history preserved
                                </p>
                            </div>
                            <form method="POST" action="{{ route('crm.webinars.restore', $removed) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                                    Restore session
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach

                @foreach($suppressedOccurrences as $suppression)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-removed-webinar-suppression="{{ $suppression->getKey() }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ data_get($suppression->meta, 'source_title') ?: 'Removed Zoom session' }}
                                </p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ data_get($suppression->meta, 'source_starts_at') ?: 'Original date unavailable' }}
                                    · kept out of future Zoom syncs
                                </p>
                            </div>
                            <form method="POST" action="{{ route('crm.webinar-occurrence-suppressions.restore', $suppression) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                                    Allow it to sync again
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach

                @if($removedWebinars->isEmpty() && $suppressedOccurrences->isEmpty())
                    <p class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                        Nothing has been intentionally removed from this webinar type.
                    </p>
                @endif
            </div>
        </section>

        @if($series->status === 'active')
            <section class="rounded-2xl border border-red-200 bg-red-50 p-5 sm:p-7" data-webinar-type-removal>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-red-700">Remove webinar type</p>

                @if($seriesRemovalPlan->canDelete())
                    <h2 class="mt-2 text-xl font-semibold text-red-950">Delete this unused webinar type</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-red-900">
                        This type has no sessions, waitlist history, or removed-provider records, so it can be deleted permanently. Series-owned message configuration that is no longer referenced will be cleaned up safely.
                    </p>
                @else
                    <h2 class="mt-2 text-xl font-semibold text-red-950">Archive this webinar type</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-red-900">
                        This type has history, so Engage Core will archive it instead of deleting it. New public registrations and normal Zoom syncing stop, while existing registrations, session history, and already-scheduled communication remain intact.
                    </p>
                    <p class="mt-2 text-xs text-red-800">
                        {{ number_format($seriesRemovalPlan->sessionCount) }} {{ \Illuminate\Support\Str::plural('session', $seriesRemovalPlan->sessionCount) }}
                        · {{ number_format($seriesRemovalPlan->waitlistSignupCount) }} waitlist {{ \Illuminate\Support\Str::plural('signup', $seriesRemovalPlan->waitlistSignupCount) }}
                        · {{ number_format($seriesRemovalPlan->suppressionCount) }} removed-provider {{ \Illuminate\Support\Str::plural('record', $seriesRemovalPlan->suppressionCount) }}
                    </p>
                @endif

                <form
                    method="POST"
                    action="{{ route('crm.webinar-series.destroy', $series) }}"
                    class="mt-4"
                    onsubmit="return confirm(@js($seriesRemovalPlan->canDelete() ? 'Permanently delete this unused webinar type?' : 'Archive this webinar type? Existing history will be preserved.'));"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                        {{ $seriesRemovalPlan->canDelete() ? 'Delete webinar type' : 'Archive webinar type' }}
                    </button>
                </form>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-type-restoration>
                <h2 class="text-lg font-semibold text-slate-950">Restore this webinar type</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Restoring makes the public registration page available again and allows normal Zoom syncing. Existing history is unchanged.
                </p>
                <form method="POST" action="{{ route('crm.webinar-series.restore', $series) }}" class="mt-4">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Restore webinar type
                    </button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.crm>