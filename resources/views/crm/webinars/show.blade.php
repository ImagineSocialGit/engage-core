<x-layouts.crm :title="$title" :heading="$heading">
    <div
        class="space-y-6"
        data-webinar-session-detail="{{ $webinar->getKey() }}"
        x-data="{ registrantsOpen: false }"
    >
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('crm.webinar-series.index') }}" class="font-semibold text-slate-600 underline">Webinar types</a>
            @if($series)
                <span class="text-slate-400">/</span>
                <a href="{{ route('crm.webinar-series.show', $series) }}" class="font-semibold text-slate-600 underline">{{ $series->title }}</a>
            @endif
            <span class="text-slate-400">/</span>
            <span class="text-slate-700">Session</span>
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
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Specific session</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ $webinar->title }}</h1>
                    @if($variant)
                        <div class="mt-2 inline-flex items-center rounded-full bg-slate-900 px-3 py-1 text-sm font-semibold text-white">
                            Market: {{ $variant->displayName() }}
                        </div>
                        <p class="mt-2 text-xs font-medium text-slate-500">{{ $variant->timezone }}</p>
                    @endif
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y · g:i A T') ?? 'Date unavailable' }}
                        @if($webinar->ends_at)
                            – {{ $webinar->ends_at->copy()->setTimezone($webinar->timezone)->format('g:i A T') }}
                        @endif
                    </p>
                </div>

                @if(function_exists('module_enabled') && module_enabled('messaging'))
                    <x-messaging.outbound-launcher
                        :url="route('crm.messaging.outbound.index', ['scope' => 'webinar', 'scope_id' => $webinar->getKey(), 'module' => 'webinars', 'period' => 'upcoming', 'embedded' => 1])"
                        label="Upcoming messages for this session"
                    />
                @endif

                <div class="flex flex-wrap gap-2">
                    @if($webinar->ends_at?->isPast())
                        <a
                            href="{{ route('crm.webinars.post-event-review.show', $webinar) }}"
                            class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                        >
                            Post-event review
                        </a>
                    @endif
                    @if($series)
                        <a
                            href="{{ route('crm.webinar-series.show', $series) }}"
                            class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Back to webinar type
                        </a>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ number_format($registrationCounts['total']) }}</div>
                    <div class="text-xs text-slate-500">Registered</div>
                </div>
                <div class="rounded-xl bg-emerald-50 p-3">
                    <div class="text-xl font-semibold text-emerald-950">{{ number_format($registrationCounts['attended']) }}</div>
                    <div class="text-xs text-emerald-700">Attended</div>
                </div>
                <div class="rounded-xl bg-amber-50 p-3">
                    <div class="text-xl font-semibold text-amber-950">{{ number_format($registrationCounts['missed']) }}</div>
                    <div class="text-xs text-amber-700">Missed</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xl font-semibold text-slate-950">{{ number_format($registrationCounts['cancelled']) }}</div>
                    <div class="text-xs text-slate-500">Cancelled</div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2" data-webinar-session-contact-audiences>
                <a
                    href="{{ route('crm.contacts.index', ['webinar_attendance' => 'session:'.$webinar->getKey().':attended']) }}"
                    class="inline-flex items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100"
                >
                    View attended contacts
                </a>
                <a
                    href="{{ route('crm.contacts.index', ['webinar_attendance' => 'session:'.$webinar->getKey().':missed']) }}"
                    class="inline-flex items-center justify-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                >
                    View missed contacts
                </a>
            </div>
        </section>

        @if(
            $webinar->provider_lifecycle_status === \App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus::Missing->value
            || data_get($webinar->meta, 'normalized.post_event.review.status') === 'pending'
        )
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5" data-webinar-session-attention>
                <h2 class="text-lg font-semibold text-amber-950">This session may need attention</h2>
                <div class="mt-2 space-y-1 text-sm text-amber-900">
                    @if($webinar->provider_lifecycle_status === \App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus::Missing->value)
                        <p>Zoom no longer returned this session in the latest authoritative schedule sync.</p>
                    @endif
                    @if(data_get($webinar->meta, 'normalized.post_event.review.status') === 'pending')
                        <p>Post-event attendance/follow-up review is still pending.</p>
                    @endif
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-webinar-session-participants>
            <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Registration insights</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">What registrants told you</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Answers are grouped once by question so common responses are easy to scan. Free-form responses remain visible separately.
                    </p>
                </div>
                <button
                    type="button"
                    x-on:click="registrantsOpen = true"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                >
                    View all {{ number_format($registrationSummary['registrant_count']) }} registrants
                </button>
            </div>

            @if($registrationSummary['questions'] === [])
                <div class="px-5 py-8 text-sm text-slate-500 sm:px-7">
                    No registration-question answers have been saved for this session yet.
                </div>
            @else
                <div class="flex flex-col gap-4 p-5 sm:p-7 lg:flex-row lg:flex-wrap lg:items-start">
                    @foreach($registrationSummary['questions'] as $question)
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5 lg:min-w-[20rem] lg:flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <h3 class="font-semibold leading-6 text-slate-950">{{ $question['label'] }}</h3>
                                <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                    {{ number_format($question['response_count']) }} responses
                                </span>
                            </div>

                            @if($question['answer_counts'] !== [])
                                <div class="mt-4 grid gap-2">
                                    @foreach($question['answer_counts'] as $answer)
                                        <div class="flex items-center justify-between gap-4 rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                            <span class="text-sm text-slate-800">{{ $answer['label'] }}</span>
                                            <span class="shrink-0 text-sm font-bold text-slate-950">{{ number_format($answer['count']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($question['custom_responses'] !== [])
                                <div class="mt-4 border-t border-slate-200 pt-4">
                                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Custom responses</div>
                                    <div class="mt-3 space-y-2">
                                        @foreach($question['custom_responses'] as $response)
                                            <div class="rounded-xl bg-white p-3 text-sm leading-6 text-slate-700 ring-1 ring-slate-200">
                                                <div>{{ $response['text'] }}</div>
                                                @if($response['respondent'])
                                                    <div class="mt-1 text-xs font-semibold text-slate-500">{{ $response['respondent'] }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <div
            x-show="registrantsOpen"
            x-cloak
            x-on:keydown.escape.window="registrantsOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Webinar registrants"
        >
            <button
                type="button"
                class="absolute inset-0 bg-slate-950/50"
                aria-label="Close registrants"
                x-on:click="registrantsOpen = false"
            ></button>

            <section class="relative z-10 flex max-h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-7">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">Registrants</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ number_format($registrationSummary['registrant_count']) }} registered for this session.</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="registrantsOpen = false"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                    >
                        Close
                    </button>
                </div>

                <div class="overflow-y-auto p-5 sm:p-7">
                    @if($registrationSummary['registrants'] === [])
                        <p class="text-sm text-slate-500">No registrants yet.</p>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($registrationSummary['registrants'] as $registrant)
                                <article class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    @if($registrant['contact_url'])
                                        <a href="{{ $registrant['contact_url'] }}" class="font-semibold text-slate-950 underline decoration-slate-300 underline-offset-2">
                                            {{ $registrant['name'] }}
                                        </a>
                                    @else
                                        <div class="font-semibold text-slate-950">{{ $registrant['name'] }}</div>
                                    @endif
                                    @if($registrant['email'])
                                        <div class="mt-1 truncate text-xs text-slate-600">{{ $registrant['email'] }}</div>
                                    @endif
                                    <div class="mt-3 inline-flex rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                        {{ $registrant['status'] }}
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>

        @if(function_exists('module_enabled') && module_enabled('messaging') && $canNotifyScheduleChanges)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-7">
                <h2 class="text-lg font-semibold text-amber-950">Webinar time changes</h2>
                <p class="mt-1 text-sm text-amber-900">Review changes from provider resync and notify eligible registrants of the old and new time.</p>
                <a href="{{ route('crm.webinars.schedule-changes.show', $webinar) }}" class="mt-3 inline-block text-sm font-semibold text-amber-950 underline">Review time changes{{ $pendingScheduleChangeCount ? ' ('.$pendingScheduleChangeCount.' to review)' : '' }}</a>
            </section>
        @endif

        @if((int) ($messageReview['message_count'] ?? 0) > 0)
            <section
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"
                data-webinar-message-summary="{{ $webinar->getKey() }}"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Messages</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Message plan</h2>
                        <p class="mt-1 text-sm text-slate-600" data-webinar-message-profile>
                            {{ $messageProfile['source'] === 'occurrence' ? 'This session has a custom schedule.' : 'Uses the webinar type schedule.' }}
                            {{ $messageProfile['effective_profile_name'] ?? 'Default' }}
                            · {{ (int) ($messageReview['message_count'] ?? 0) }} messages
                        </p>
                    </div>
                    @if($series)
                        <a
                            href="{{ route('crm.webinar-series.show', ['series' => $series, 'messages' => 1]) }}#message-plan"
                            class="text-sm font-semibold text-slate-700 underline"
                        >
                            Review webinar type messages
                        </a>
                    @endif
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-webinar-session-operations>
            <h2 class="text-lg font-semibold text-slate-950">Session operations</h2>
            <p class="mt-1 text-sm text-slate-600">
                These controls affect this date only, not the whole webinar type.
            </p>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 p-4">
                    <h3 class="text-sm font-semibold text-slate-900">Message plan override</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        Leave this on the webinar type's plan unless this specific session needs different timing.
                    </p>
                    <form method="POST" action="{{ route('crm.webinars.schedule-profile.update', $webinar) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                        @csrf
                        @method('PATCH')
                        <select name="webinar_schedule_profile_id" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">Use webinar type plan</option>
                            @foreach($scheduleProfiles as $profile)
                                <option value="{{ $profile->getKey() }}" @selected((int) $webinar->webinar_schedule_profile_id === (int) $profile->getKey())>
                                    {{ $profile->name }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Save</button>
                    </form>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                    <h3 class="text-sm font-semibold text-red-950">Remove this session</h3>
                    <p class="mt-1 text-xs leading-5 text-red-800">
                        Sessions with history are hidden and recoverable. Empty synced sessions are kept out of future Zoom syncs until you restore them.
                    </p>
                    <form
                        method="POST"
                        action="{{ route('crm.webinars.destroy', $webinar) }}"
                        class="mt-3"
                        onsubmit="return confirm('Remove this session? You can review removed sessions from the webinar type page.');"
                    >
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700">
                            Remove session
                        </button>
                    </form>
                </div>
            </div>

            @if($replacementCandidates->isNotEmpty())
                <details class="mt-4 rounded-xl border border-slate-200 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-900">Replace this session with another synced session</summary>
                    <form method="POST" action="{{ route('crm.webinars.replacements.store', $webinar) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                        @csrf
                        <select name="replacement_webinar_id" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                            <option value="">Choose replacement</option>
                            @foreach($replacementCandidates as $candidate)
                                <option value="{{ $candidate->getKey() }}">
                                    {{ $candidate->title }} — {{ $candidate->starts_at?->copy()->setTimezone($candidate->timezone)->format('M j, Y · g:i A T') }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Prepare replacement</button>
                    </form>
                </details>
            @endif
        </section>
    </div>
</x-layouts.crm>