<x-layouts.crm
    :title="$campaign->name"
    heading="Campaign"
    :subheading="$campaign->name"
    module="campaigns"
>
    @php
        $statusLabel = match($campaign->status) {
            \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE => 'Active',
            \App\Modules\Campaigns\Models\Campaign::STATUS_INACTIVE => 'Off',
            \App\Modules\Campaigns\Models\Campaign::STATUS_ARCHIVED => 'Archived',
            default => \Illuminate\Support\Str::headline((string) $campaign->status),
        };

        $statusClass = match($campaign->status) {
            \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
            \App\Modules\Campaigns\Models\Campaign::STATUS_INACTIVE => 'bg-slate-100 text-slate-700 ring-slate-200',
            default => 'bg-amber-100 text-amber-900 ring-amber-200',
        };

        $campaignStyleLabel = $campaign->usesRecurringAllocation()
            ? 'Ongoing outreach'
            : 'Follow-up series';
    @endphp

    <div class="min-w-0 space-y-6">
        <div>
            <a href="{{ route('crm.campaigns.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">
                &larr; All campaigns
            </a>
        </div>

        @if(session('status'))
            <div class="break-words rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if(session('error'))
            <div class="break-words rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900">
                {{ session('error') }}
            </div>
        @endif

        @if(data_get($campaign->meta, 'audience_completion.notified_at'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-950">
                <p class="font-bold">The current audience has finished this Campaign.</p>
                <p class="mt-1">{{ number_format((int) data_get($campaign->meta, 'audience_completion.completed_count', 0)) }} leads completed their messages. New eligible leads can still join this Campaign.</p>
                <a href="{{ route('crm.campaigns.audience.index', $campaign) }}" class="mt-2 inline-block font-semibold underline">Review audience progress</a>
            </div>
        @endif

        <section class="min-w-0 rounded-3xl border border-rose-200 bg-white/95 p-4 shadow-sm sm:p-8">
            <div class="flex min-w-0 flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-3">
                        <h2 class="min-w-0 break-words text-2xl font-semibold tracking-tight text-slate-950">
                            {{ $campaign->name }}
                        </h2>
                        <span class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset {{ $statusClass }}">
                            {{ $statusLabel }}
                        </span>
                        <span class="inline-flex shrink-0 items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 ring-1 ring-inset ring-slate-200">
                            {{ $campaignStyleLabel }}
                        </span>
                    </div>

                    @if($campaign->description)
                        <p class="mt-3 max-w-3xl break-words text-sm leading-6 text-slate-600">
                            {{ $campaign->description }}
                        </p>
                    @endif
                </div>

                @if(function_exists('module_enabled') && module_enabled('messaging'))
                    <x-messaging.outbound-launcher
                        :url="route('crm.messaging.outbound.index', ['scope' => 'campaign', 'scope_id' => $campaign->getKey(), 'module' => 'campaigns', 'period' => 'upcoming', 'embedded' => 1])"
                        label="Upcoming messages"
                    />
                @endif

                <div class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-wrap">
                    <a
                        href="{{ route('crm.campaigns.edit', $campaign) }}"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        Edit campaign
                    </a>

                    @if($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.deactivate', $campaign) }}"
                            class="w-full sm:w-auto"
                            onsubmit="return confirm('Turn this campaign off? Current journeys will stop and pending campaign messages will be skipped.');"
                        >
                            @csrf
                            @method('PATCH')
                            <button
                                type="submit"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-red-700 px-5 text-sm font-bold text-white transition hover:bg-red-800 sm:w-auto"
                            >
                                Turn off
                            </button>
                        </form>
                    @elseif($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_INACTIVE)
                        <form method="POST" action="{{ route('crm.campaigns.activate', $campaign) }}" class="w-full sm:w-auto">
                            @csrf
                            @method('PATCH')
                            <button
                                type="submit"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                            >
                                Turn on
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        @if($campaign->usesRecurringAllocation() && is_array($allocationSettings))
            <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">How it works</p>
                        <h2 class="mt-2 text-lg font-semibold text-slate-950">Steady outreach to fresh eligible leads</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            On each outreach round, every active message gets a different group of eligible leads. A lead can be chosen again only after the waiting period below.
                        </p>
                    </div>
                    <a href="{{ route('crm.campaigns.edit', ['campaign' => $campaign, 'panel' => 'start']) }}" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Change outreach settings</a>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-xl font-bold text-slate-950">Every {{ number_format($allocationSettings['run_every_days']) }} {{ \Illuminate\Support\Str::plural('day', $allocationSettings['run_every_days']) }}</div>
                        <div class="mt-1 text-sm text-slate-600">Choose a new group of leads</div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-xl font-bold text-slate-950">{{ number_format($allocationSettings['allocation_size_per_message']) }}</div>
                        <div class="mt-1 text-sm text-slate-600">Leads for each message</div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-xl font-bold text-slate-950">{{ number_format($allocationSettings['recipient_cooldown_days']) }} {{ \Illuminate\Support\Str::plural('day', $allocationSettings['recipient_cooldown_days']) }}</div>
                        <div class="mt-1 text-sm text-slate-600">Before the same lead can be chosen again</div>
                    </div>
                </div>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">How it works</p>
                        <h2 class="mt-2 text-lg font-semibold text-slate-950">A lead moves through the messages in order</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Each lead starts at the beginning and follows {{ number_format($workspace['message_step_count']) }} {{ \Illuminate\Support\Str::plural('message', $workspace['message_step_count']) }} in order, using the timing you set between them.
                        </p>
                    </div>
                    <a href="{{ route('crm.campaigns.edit', ['campaign' => $campaign, 'panel' => 'schedule']) }}" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Review message timing</a>
                </div>
            </section>
        @endif

        @if($campaign->usesRecurringAllocation())
            <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Recent outreach</h2>
                        <p class="mt-1 text-sm text-slate-600">See who was selected recently or preview the next group before it starts.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('crm.campaigns.runs.preview', $campaign) }}" class="inline-flex min-h-10 items-center rounded-full bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800">Preview next outreach</a>
                        <a href="{{ route('crm.campaigns.runs.index', $campaign) }}" class="text-sm font-semibold text-slate-900 underline">Outreach history</a>
                    </div>
                </div>
                @forelse($recentAllocationRuns as $run)
                    <a href="{{ route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run]) }}" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3 hover:bg-slate-50">
                        <span class="font-semibold text-slate-950">{{ $run->scheduled_for?->format('M j, Y g:i A') ?? 'Outreach #'.$run->getKey() }}</span>
                        <span class="text-sm text-slate-600">{{ \Illuminate\Support\Str::headline($run->status) }} · {{ number_format($run->assignments_count) }} leads selected · {{ number_format($run->sent_messages_count) }} sent</span>
                    </a>
                @empty
                    <p class="mt-4 text-sm text-slate-600">No outreach has run yet.</p>
                @endforelse
            </section>
        @endif

        <section class="grid min-w-0 gap-3 sm:grid-cols-3">
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['active_enrollment_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Active leads</div>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['pending_message_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Messages waiting to send</div>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['message_step_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Messages in this campaign</div>
            </div>
        </section>

        <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Leads in this campaign</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ number_format($audienceSummary['matching']) }} leads match the current audience rules. {{ number_format($audienceSummary['not_started']) }} match but have not started, and {{ number_format($audienceSummary['enrolled']) }} have been in the campaign.</p>
                    <p class="mt-2 text-xs text-slate-500">Permission and delivery checks happen separately when a message is prepared to send.</p>
                </div>
                <a href="{{ route('crm.campaigns.audience.index', $campaign) }}" class="inline-flex min-h-11 items-center rounded-full bg-slate-950 px-5 text-sm font-semibold text-white hover:bg-slate-800">View leads and progress</a>
            </div>
            <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-slate-700">
                @foreach(['active', 'paused', 'completed', 'exited', 'cancelled'] as $status)
                    <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'status' => $status]) }}" class="rounded-full bg-slate-100 px-3 py-2 hover:bg-slate-200">{{ \Illuminate\Support\Str::headline($status) }} {{ number_format($audienceSummary['statuses'][$status]) }}</a>
                @endforeach
            </div>
        </section>

        <details id="campaign-email-delivery-pacing" class="min-w-0 rounded-3xl border border-slate-200 bg-white shadow-sm" @if($errors->any()) open @endif>
            <summary class="cursor-pointer list-none p-4 sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Email delivery</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ $sendPattern['mode_label'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Open to change sending days, hours, or the daily email limit.</p>
                    </div>
                    <span class="text-sm font-bold text-slate-700">Change settings</span>
                </div>
            </summary>

            <div class="border-t border-slate-200 p-4 sm:p-7">

            @if($campaign->usesRecurringAllocation() && $sendPattern['mode'] === 'spread' && $workspace['marketing_email_step_count'] * $allocationSettings['allocation_size_per_message'] > $sendPattern['daily_limit'])
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                    One outreach round can make up to {{ number_format($workspace['marketing_email_step_count'] * $allocationSettings['allocation_size_per_message']) }} marketing emails across {{ number_format($workspace['marketing_email_step_count']) }} active email {{ \Illuminate\Support\Str::plural('message', $workspace['marketing_email_step_count']) }}. This campaign is set to send up to {{ number_format($sendPattern['daily_limit']) }}. If more emails are ready on the same day, the rest move to the next allowed sending time.
                </div>
            @endif

            @if($sendPattern['mode'] === 'spread')
                <a href="{{ route('crm.campaigns.pacing-override.preview', $campaign) }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-900 hover:bg-slate-50">Send more today</a>
            @endif

            <form method="POST" action="{{ route('crm.campaigns.send-pattern.update', $campaign) }}" class="mt-6 space-y-5">
                @csrf
                @method('PATCH')

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="rounded-2xl border border-slate-200 p-4">
                        <span class="flex items-start gap-3">
                            <input
                                type="radio"
                                name="mode"
                                value="as_due"
                                @checked(old('mode', $sendPattern['mode']) === 'as_due')
                                class="mt-1 border-slate-300"
                            >
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">Send as due</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-600">Send each email as soon as its campaign timing allows, without a daily campaign limit.</span>
                            </span>
                        </span>
                    </label>

                    <label class="rounded-2xl border border-slate-200 p-4">
                        <span class="flex items-start gap-3">
                            <input
                                type="radio"
                                name="mode"
                                value="spread"
                                @checked(old('mode', $sendPattern['mode']) === 'spread')
                                class="mt-1 border-slate-300"
                            >
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">Spread throughout the day</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-600">Spread campaign emails across the days and hours you choose. If one day fills up, the rest move to the next allowed time.</span>
                            </span>
                        </span>
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-800">Daily email limit</label>
                        <input
                            type="number"
                            name="daily_limit"
                            min="1"
                            max="100000"
                            value="{{ old('daily_limit', $sendPattern['daily_limit']) }}"
                            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-800">Start sending</label>
                        <input
                            type="time"
                            name="window_start"
                            value="{{ old('window_start', $sendPattern['window_start']) }}"
                            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-800">Stop sending</label>
                        <input
                            type="time"
                            name="window_end"
                            value="{{ old('window_end', $sendPattern['window_end']) }}"
                            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-800">Timezone</label>
                        <select name="timezone" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                            @foreach($sendPatternTimezones as $timezone)
                                <option value="{{ $timezone }}" @selected(old('timezone', $sendPattern['timezone']) === $timezone)>
                                    {{ $timezone }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <div class="text-sm font-semibold text-slate-800">Sending days</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach([
                            1 => 'Monday',
                            2 => 'Tuesday',
                            3 => 'Wednesday',
                            4 => 'Thursday',
                            5 => 'Friday',
                            6 => 'Saturday',
                            7 => 'Sunday',
                        ] as $dayNumber => $dayLabel)
                            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    name="days_of_week[]"
                                    value="{{ $dayNumber }}"
                                    @checked(in_array($dayNumber, old('days_of_week', $sendPattern['days_of_week']), true))
                                    class="rounded border-slate-300"
                                >
                                {{ $dayLabel }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="max-w-3xl text-xs leading-5 text-slate-500">
                        Changes affect emails scheduled after you save. Emails already scheduled keep their current send times.
                    </p>
                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800"
                    >
                        Save email delivery
                    </button>
                </div>
            </form>
            </div>
        </details>

        @if($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
            <section class="break-words rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950 sm:p-5">
                Turning this campaign off stops the current journeys and skips pending campaign messages. Turning it back on later allows new leads to start, but it does not restart journeys that were stopped.
            </section>
        @endif
    </div>
</x-layouts.crm>