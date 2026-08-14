<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-bold">The follow-up plan could not be approved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Post-event review
                    </p>
                    <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950">
                        {{ $webinar->title }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Confirm what should happen with the replay before replay-dependent follow-ups continue.
                    </p>

                    <dl class="mt-5 flex flex-wrap gap-x-8 gap-y-3 text-sm">
                        <div>
                            <dt class="font-bold text-slate-900">Ended</dt>
                            <dd class="text-slate-600">
                                {{ $webinar->ends_at?->copy()->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') ?? 'Unknown' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-900">Attended</dt>
                            <dd class="text-slate-600">{{ $attendedCount }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-900">Missed</dt>
                            <dd class="text-slate-600">{{ $missedCount }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-900">Current replay</dt>
                            <dd class="text-slate-600">{{ filled($webinar->playback_url) ? 'Detected' : 'Not detected yet' }}</dd>
                        </div>
                    </dl>
                </div>

                <a
                    href="{{ route('crm.webinar-series.index') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                >
                    Back to webinars
                </a>
            </div>
        </section>

        @php
            $savedMode = old('playback_mode', $review['playback_mode'] ?? null);
            $initialMode = in_array($savedMode, ['current', 'alternate', 'none'], true)
                ? $savedMode
                : 'current';
        @endphp

        <form
            method="POST"
            action="{{ route('crm.webinars.post-event-review.update', $webinar) }}"
            class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
            x-data="{ mode: @js($initialMode) }"
        >
            @csrf
            @method('PATCH')

            <div class="max-w-3xl">
                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                    Choose the replay plan
                </p>
                <h2 class="mt-2 text-xl font-black tracking-tight text-slate-950">
                    What should registrants receive?
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Engage Core checks the selected recording with the webinar provider when you approve it and checks again immediately before each replay-dependent message is sent.
                </p>
            </div>

            @if (! $attendanceReady)
                <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Attendance is still syncing. You can suppress the replay now, but wait for attendance to finish before approving a current or previous recording so attendees and missed registrants are classified correctly.
                </div>
            @endif

            <div class="mt-6 space-y-3">
                <label class="flex cursor-pointer gap-3 rounded-2xl border border-slate-200 p-4">
                    <input
                        type="radio"
                        name="playback_mode"
                        value="current"
                        x-model="mode"
                        class="mt-1"
                    >
                    <span>
                        <span class="block font-bold text-slate-950">Use this webinar’s recording</span>
                        <span class="mt-1 block text-sm text-slate-600">
                            Verify the recording from this webinar and use it in the normal attendee and missed follow-ups.
                        </span>
                    </span>
                </label>

                <label class="block cursor-pointer rounded-2xl border border-slate-200 p-4">
                    <span class="flex gap-3">
                        <input
                            type="radio"
                            name="playback_mode"
                            value="alternate"
                            x-model="mode"
                            class="mt-1"
                        >
                        <span>
                            <span class="block font-bold text-slate-950">Use a previous recording</span>
                            <span class="mt-1 block text-sm text-slate-600">
                                Choose another completed occurrence from this webinar series.
                            </span>
                        </span>
                    </span>

                    <div class="mt-4 pl-7" x-show="mode === 'alternate'" x-cloak>
                        <select
                            name="alternate_webinar_id"
                            class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                        >
                            <option value="">Choose a previous webinar</option>
                            @foreach ($alternateWebinars as $alternate)
                                <option
                                    value="{{ $alternate->getKey() }}"
                                    @selected((string) old('alternate_webinar_id', $review['source_webinar_id'] ?? '') === (string) $alternate->getKey())
                                >
                                    {{ $alternate->ends_at?->copy()->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') ?? 'Completed webinar' }}
                                    — {{ filled($alternate->playback_url) ? 'replay detected' : 'provider check required' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </label>

                <label class="flex cursor-pointer gap-3 rounded-2xl border border-slate-200 p-4">
                    <input
                        type="radio"
                        name="playback_mode"
                        value="none"
                        x-model="mode"
                        class="mt-1"
                    >
                    <span>
                        <span class="block font-bold text-slate-950">Do not send a replay</span>
                        <span class="mt-1 block text-sm text-slate-600">
                            Suppress this webinar’s replay follow-up instead of sending a partial, deleted, or unwanted recording.
                        </span>
                    </span>
                </label>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white transition hover:bg-slate-800"
                >
                    Confirm follow-up plan
                </button>

                @if (($review['status'] ?? null) === 'pending')
                    <span class="text-sm font-semibold text-amber-700">Follow-ups are waiting for this review.</span>
                @elseif (($review['status'] ?? null) === 'approved')
                    <span class="text-sm font-semibold text-emerald-700">A replay plan has been approved.</span>
                @elseif (($review['status'] ?? null) === 'suppressed')
                    <span class="text-sm font-semibold text-slate-600">Replay sending is suppressed.</span>
                @endif
            </div>
        </form>
    </div>
</x-layouts.crm>