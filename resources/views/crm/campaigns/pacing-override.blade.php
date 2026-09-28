<x-layouts.crm
    :title="$campaign->name.' · Today’s email pacing'"
    heading="Today’s email pacing"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="space-y-6">
        <a href="{{ route('crm.campaigns.show', $campaign) }}#campaign-email-delivery-pacing" class="inline-block text-sm font-semibold text-slate-600 hover:text-slate-950">&larr; Campaign email pacing</a>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <h2 class="text-xl font-semibold text-slate-950">Use the remaining window</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Choose how many already queued, ready marketing emails to bring into today’s remaining sending window. They will be spaced from now until just before the window closes. This one-time change leaves your regular pacing settings alone. Message permission checks still run at send time.</p>

            @if($errors->any())
                <p class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first() }}</p>
            @endif

            @if($preview['reason'])
                <p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">{{ $preview['reason'] }}</p>
            @endif

            @if($preview['available'])
                <div class="mt-5 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">
                    <p><strong>{{ number_format($preview['candidate_count']) }}{{ $preview['candidate_count'] === $preview['max_target'] ? '+' : '' }}</strong> pending emails ready to move.</p>
                    <p class="mt-1">Sending window closes {{ $preview['window_end']->format('g:i A') }} {{ $preview['timezone'] }}.</p>
                </div>
                <form method="POST" action="{{ route('crm.campaigns.pacing-override.store', $campaign) }}" class="mt-6 flex flex-wrap items-end gap-3">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ $requestKey }}">
                    <label class="block">
                        <span class="block text-sm font-semibold text-slate-800">Emails to re-space today</span>
                        <input type="number" name="target" min="1" max="{{ $preview['max_target'] }}" value="{{ old('target', min(100, $preview['candidate_count'])) }}" class="mt-2 block min-h-11 w-40 rounded-xl border-slate-300 text-sm font-semibold text-slate-950">
                    </label>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">Apply today’s override</button>
                </form>
                <p class="mt-3 text-xs text-slate-500">Only messages whose original requested time has arrived are eligible. Already held, sent, or individually rescheduled messages stay as they are.</p>
            @endif
        </section>
    </div>
</x-layouts.crm>