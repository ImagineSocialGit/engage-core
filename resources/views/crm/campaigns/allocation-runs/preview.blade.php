<x-layouts.crm
    :title="$campaign->name.' · Preview allocation run'"
    heading="Preview allocation run"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="space-y-6">
        <a href="{{ route('crm.campaigns.show', $campaign) }}" class="inline-block text-sm font-semibold text-slate-600 hover:text-slate-950">&larr; Campaign</a>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <h2 class="text-xl font-semibold text-slate-950">Next run</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">This read-only preview checks current allocation membership, eligibility, prior messages, exclusions, and cooldown. It reserves no leads. The run selects again when it processes, and message delivery gates may reduce the final counts.</p>

            @if($errors->any())
                <p class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first() }}</p>
            @endif

            @if($preview['reason'])
                <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">{{ $preview['reason'] }}</p>
            @endif

            @if($preview['messages'] !== [])
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($preview['messages'] as $message)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="font-semibold text-slate-950">{{ $message['name'] }}</p>
                            <p class="mt-2 text-sm text-slate-700">
                                {{ $message['capped'] ? 'At least ' : '' }}{{ number_format($message['potential']) }} potential {{ \Illuminate\Support\Str::plural('lead', $message['potential']) }}
                                · up to {{ number_format($preview['quota']) }} per run
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($preview['can_start'])
                <form method="POST" action="{{ route('crm.campaigns.runs.store', $campaign) }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ $requestKey }}">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">Start run now</button>
                </form>
                <p class="mt-3 text-xs text-slate-500">Starting now bypasses the normal cadence. The next scheduled run will use this run as its new cadence anchor.</p>
            @endif
        </section>
    </div>
</x-layouts.crm>