<x-layouts.crm :title="$webinar->title.' · Schedule changes'" :heading="'Schedule changes · '.$webinar->title">
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-6">
        <a href="{{ route('crm.webinars.show', $webinar) }}" class="text-sm font-semibold text-slate-700 underline">Back to webinar</a>

        @if(session('status'))
            <p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <p class="rounded-xl bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() }}</p>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-slate-950">Notify registrants of a new time</h1>
            <p class="mt-2 text-sm text-slate-600">Provider resync records changes here. Review the exact old and new times before sending. Unsubscribed, cancelled, or unreachable registrants are skipped.</p>
            @if($series)
                <a href="{{ route('crm.webinar-series.time-change-settings.show', $series) }}" class="mt-3 inline-block text-sm font-semibold text-slate-800 underline">Manage time-change message copy and automatic sending</a>
            @endif

            @if($latest)
                @php($labels = $copy->labels($latest))
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="font-semibold text-slate-700">Previous time</dt><dd class="mt-1">{{ $labels['previous'] }}</dd></div>
                    <div><dt class="font-semibold text-slate-700">New time</dt><dd class="mt-1">{{ $labels['current'] }}</dd></div>
                </dl>
                <p class="mt-4 text-sm text-slate-700">{{ $registrationsCount }} active registrations; only registrations made before this change can receive a notice. Consent, destination, and delivery checks also apply. {{ $latest->messages_queued }} notices queued for this change.</p>
                <p class="mt-2 text-sm text-slate-700">Delivery: {{ $latest->notification_mode === 'automatic' ? 'automatic on resync' : 'manual review' }}.</p>

                @if($latest->status === \App\Modules\Webinars\Models\WebinarScheduleChange::STATUS_PENDING && $current && count($channels))
                    <form method="POST" action="{{ route('crm.webinars.schedule-changes.store', [$webinar, $latest]) }}" class="mt-5 space-y-4">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-semibold text-slate-800">Notify through</legend>
                            <div class="mt-2 flex gap-5">
                                @foreach($channels as $channel)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="channels[]" value="{{ $channel }}" @checked(in_array($channel, old('channels', ['email'])))> {{ strtoupper($channel) }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label class="flex items-start gap-2 text-sm text-slate-800"><input type="checkbox" name="confirm" value="1" required> I reviewed both times and want to notify eligible registrants.</label>
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white" onclick="return confirm('Queue these webinar time-change notices?');">Queue notices</button>
                    </form>
                @else
                    <p class="mt-4 text-sm font-semibold text-slate-700">Status: {{ ucfirst($latest->status) }}{{ ! $current ? ' · This change is no longer the webinar’s current time.' : '' }}</p>
                @endif
            @else
                <p class="mt-4 text-sm text-slate-600">No schedule change has been recorded for this webinar.</p>
            @endif
        </section>

        @if($changes->count() > 1)
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Earlier changes</h2>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach($changes->skip(1) as $change)
                        @php($times = $copy->labels($change))
                        <li class="py-3">{{ $times['previous'] }} → {{ $times['current'] }} · {{ ucfirst($change->status) }} · {{ $change->messages_queued }} queued</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.crm>