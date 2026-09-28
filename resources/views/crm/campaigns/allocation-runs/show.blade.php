<x-layouts.crm
    :title="$campaign->name.' · Run #'.$run->getKey()"
    heading="Allocation run"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="space-y-6">
        <a href="{{ route('crm.campaigns.runs.index', $campaign) }}" class="inline-block text-sm font-semibold text-slate-600 hover:text-slate-950">&larr; All runs</a>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Run #{{ $run->getKey() }}</h2>
                    <p class="mt-2 text-sm text-slate-600">Scheduled {{ $run->scheduled_for?->format('M j, Y g:i A') ?? '—' }} · {{ \Illuminate\Support\Str::headline($run->status) }}</p>
                    @if($run->started_at)
                        <p class="mt-1 text-xs text-slate-500">Started {{ $run->started_at->format('M j, Y g:i A') }}@if($run->completed_at) · Finished {{ $run->completed_at->format('M j, Y g:i A') }}@endif</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-3 text-sm font-semibold text-slate-700">
                    <span>{{ number_format($run->assignments_count) }} assigned</span>
                    <span>{{ number_format($run->planned_messages_count) }} planned</span>
                    <span>{{ number_format($run->sent_messages_count) }} sent</span>
                </div>
            </div>

            <h3 class="mt-7 text-sm font-bold text-slate-950">Messages in this run</h3>
            @if($messages->isEmpty())
                <p class="mt-3 text-sm text-slate-600">No leads were assigned to a message in this run.</p>
            @else
                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($messages as $message)
                        <a href="{{ route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run, 'message' => $message['key']]) }}" class="rounded-2xl border p-4 hover:bg-slate-50 {{ $messageKey === $message['key'] ? 'border-slate-950 bg-slate-50' : 'border-slate-200' }}">
                            <p class="font-semibold text-slate-950">{{ $message['name'] }}</p>
                            <p class="mt-2 text-xs text-slate-600">{{ number_format($message['assigned']) }} assigned · {{ number_format($message['planned']) }} planned · {{ number_format($message['sent']) }} sent</p>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Assigned leads</h2>
                    <p class="mt-1 text-sm text-slate-600">Showing leads you can view. Delivery status may change after the run finishes.</p>
                </div>
                @if($messageKey !== null)
                    <a href="{{ route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run]) }}" class="text-sm font-semibold text-slate-700 underline">Clear message filter</a>
                @endif
            </div>
            <div class="mt-5 divide-y divide-slate-200">
                @forelse($assignments as $assignment)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0">
                        <div>
                            <a href="{{ route('crm.contacts.show', $assignment->contact) }}" class="font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">{{ trim((string) ($assignment->contact->name ?: trim($assignment->contact->first_name.' '.$assignment->contact->last_name))) ?: ($assignment->contact->email ?: 'Lead #'.$assignment->contact_id) }}</a>
                            <p class="mt-1 text-xs text-slate-500">{{ $messages->firstWhere('key', $assignment->message_step_key)['name'] ?? $assignment->message_step_key }}</p>
                        </div>
                        <div class="text-sm text-slate-700">
                            <p class="font-semibold">{{ $assignment->scheduledMessage ? \Illuminate\Support\Str::headline($assignment->scheduledMessage->status) : 'Awaiting schedule' }}</p>
                            @if($assignment->sent_at)
                                <p class="mt-1 text-xs text-slate-500">Sent {{ $assignment->sent_at->format('M j, Y g:i A') }}</p>
                            @elseif($assignment->scheduledMessage?->send_at)
                                <p class="mt-1 text-xs text-slate-500">Due {{ $assignment->scheduledMessage->send_at->format('M j, Y g:i A') }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-sm text-slate-600">No assigned leads are visible in this view.</p>
                @endforelse
            </div>
            @if($assignments->hasPages())
                <div class="mt-6">{{ $assignments->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.crm>