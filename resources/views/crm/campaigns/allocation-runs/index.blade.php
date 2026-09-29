<x-layouts.crm
    :title="$campaign->name.' · Outreach history'"
    heading="Outreach history"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="space-y-6">
        <a href="{{ route('crm.campaigns.show', $campaign) }}" class="inline-block text-sm font-semibold text-slate-600 hover:text-slate-950">&larr; Campaign</a>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-slate-950">Recent outreach</h2>
                <a href="{{ route('crm.campaigns.runs.preview', $campaign) }}" class="inline-flex min-h-10 items-center rounded-full bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800">Preview next outreach</a>
            </div>
            <p class="mt-2 text-sm text-slate-600">Lead selections are saved when each outreach round finishes. Sent totals update as the scheduled messages go out.</p>

            <div class="mt-5 space-y-3">
                @forelse($runs as $run)
                    <a href="{{ route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run]) }}" class="block rounded-2xl border border-slate-200 p-4 hover:bg-slate-50">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $run->scheduled_for?->format('M j, Y g:i A') ?? 'Outreach #'.$run->getKey() }}</p>
                                <p class="mt-1 text-xs text-slate-500">Outreach #{{ $run->getKey() }} · {{ \Illuminate\Support\Str::headline($run->status) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3 text-sm font-semibold text-slate-700">
                                <span>{{ number_format($run->assignments_count) }} leads selected</span>
                                <span>{{ number_format($run->planned_messages_count) }} scheduled</span>
                                <span>{{ number_format($run->sent_messages_count) }} sent</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="rounded-2xl bg-slate-50 p-5 text-sm text-slate-600">No outreach yet. Automatic outreach will appear here after it starts.</p>
                @endforelse
            </div>

            @if($runs->hasPages())
                <div class="mt-6">{{ $runs->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.crm>