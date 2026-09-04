
<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6" data-webinar-session-detail="{{ $webinar->getKey() }}">
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
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y · g:i A T') ?? 'Date unavailable' }}
                        @if($webinar->ends_at)
                            – {{ $webinar->ends_at->copy()->setTimezone($webinar->timezone)->format('g:i A T') }}
                        @endif
                    </p>
                </div>

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
            <div class="border-b border-slate-200 px-5 py-4 sm:px-7">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">People</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Registration and participation</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Attendance and time-in-session come from the provider attendance record. Registration answers are the questions collected when the person signed up.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 sm:px-7">Person</th>
                            <th class="px-5 py-3">Result</th>
                            <th class="px-5 py-3">Participation</th>
                            <th class="px-5 py-3">Registration answers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($registrations as $registration)
                            <tr>
                                <td class="px-5 py-4 align-top sm:px-7">
                                    @if($registration->contact)
                                        <a href="{{ route('crm.contacts.show', $registration->contact) }}" class="font-semibold text-slate-900 underline">
                                            {{ $registration->contact->name ?? $registration->contact->email ?? 'Contact' }}
                                        </a>
                                        <div class="mt-1 text-xs text-slate-500">{{ $registration->contact->email }}</div>
                                    @else
                                        <div class="font-semibold text-slate-900">{{ data_get($registration->meta, 'email') ?: 'Unlinked registration' }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ ucfirst((string) $registration->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if(data_get($registration->meta, 'attendance.status') === 'attended' || $registration->attended_at)
                                        <div class="space-y-1 text-xs text-slate-600">
                                            @if(data_get($registration->meta, 'attendance.duration'))
                                                <div>{{ round(((int) data_get($registration->meta, 'attendance.duration')) / 60) }} minutes</div>
                                            @endif
                                            @if(data_get($registration->meta, 'attendance.join_time'))
                                                <div>Joined: {{ data_get($registration->meta, 'attendance.join_time') }}</div>
                                            @endif
                                            @if(data_get($registration->meta, 'attendance.leave_time'))
                                                <div>Left: {{ data_get($registration->meta, 'attendance.leave_time') }}</div>
                                            @endif
                                            @if(data_get($registration->meta, 'attendance.provider'))
                                                <div>Source: {{ ucfirst((string) data_get($registration->meta, 'attendance.provider')) }}</div>
                                            @endif
                                        </div>
                                    @elseif($registration->status === 'missed')
                                        <span class="text-xs font-semibold text-amber-700">Did not attend</span>
                                    @else
                                        <span class="text-xs text-slate-400">No participation record yet</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if($registration->responses->isNotEmpty())
                                        <dl class="space-y-2 text-xs">
                                            @foreach($registration->responses as $answer)
                                                <div>
                                                    <dt class="font-semibold text-slate-700">{{ $answer->question_label ?: $answer->question_key }}</dt>
                                                    <dd class="mt-0.5 text-slate-600">{{ $answer->answer_label ?: $answer->answer_text ?: $answer->answer_key ?: '—' }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @else
                                        <span class="text-xs text-slate-400">No saved answers</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-center text-sm text-slate-500">No registrations for this session.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($registrations->hasPages())
                <div class="border-t border-slate-200 px-5 py-4 sm:px-7">
                    {{ $registrations->links() }}
                </div>
            @endif
        </section>

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