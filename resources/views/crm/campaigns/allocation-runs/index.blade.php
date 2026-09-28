<x-layouts.crm
    :title="$campaign->name.' · Allocation runs'"
    heading="Allocation runs"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="space-y-6">
        <a href="{{ route('crm.campaigns.show', $campaign) }}" class="inline-block text-sm font-semibold text-slate-600 hover:text-slate-950">&larr; Campaign</a>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-slate-950">Run history</h2>
                <a href="{{ route('crm.campaigns.runs.preview', $campaign) }}" class="inline-flex min-h-10 items-center rounded-full bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800">Preview next run</a>
            </div>
            <p class="mt-2 text-sm text-slate-600">Assignment totals are fixed when a run completes. Sent totals update as scheduled messages are delivered.</p>

            <div class="mt-5 space-y-3">
                @forelse($runs as $run)
                    <a href="{{ route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run]) }}" class="block rounded-2xl border border-slate-200 p-4 hover:bg-slate-50">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $run->scheduled_for?->format('M j, Y g:i A') ?? 'Run #'.$run->getKey() }}</p>
                                <p class="mt-1 text-xs text-slate-500">Run #{{ $run->getKey() }} · {{ \Illuminate\Support\Str::headline($run->status) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3 text-sm font-semibold text-slate-700">
                                <span>{{ number_format($run->assignments_count) }} assigned</span>
                                <span>{{ number_format($run->planned_messages_count) }} planned</span>
                                <span>{{ number_format($run->sent_messages_count) }} sent</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="rounded-2xl bg-slate-50 p-5 text-sm text-slate-600">No allocation runs yet. Runs appear here after the scheduler starts them.</p>
                @endforelse
            </div>

            @if($runs->hasPages())
                <div class="mt-6">{{ $runs->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.crm>