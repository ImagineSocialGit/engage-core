<x-layouts.crm
    title="Campaigns"
    heading="Campaigns"
    subheading="Review your ongoing follow-up and nurture campaigns."
    module="campaigns"
>
    <div class="min-w-0 space-y-6">
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

        <section class="min-w-0 rounded-3xl border border-rose-200 bg-white/95 p-4 shadow-sm sm:p-8">
            <div class="flex min-w-0 flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">
                        Campaign workspace
                    </p>
                    <h2 class="mt-2 break-words text-2xl font-semibold tracking-tight text-slate-950">
                        See what is running and what needs attention
                    </h2>
                    <p class="mt-3 max-w-3xl break-words text-sm leading-6 text-slate-600">
                        Open a campaign to review its setup, current participants, message schedule, and live status.
                    </p>
                </div>

                <div class="w-full min-w-0 space-y-3 lg:w-auto">
                    <div class="flex justify-stretch lg:justify-end">
                        <a
                            href="{{ route('crm.campaigns.create') }}"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                            data-create-campaign-link
                        >
                            Create campaign
                        </a>
                    </div>

                    <div class="grid w-full min-w-0 gap-2 text-center sm:grid-cols-3 lg:min-w-80">
                        <div class="min-w-0 rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-3">
                            <div class="text-xl font-bold text-emerald-950">{{ $statusCounts['active'] ?? 0 }}</div>
                            <div class="mt-1 text-xs font-semibold text-emerald-800">Active</div>
                        </div>
                        <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                            <div class="text-xl font-bold text-slate-950">{{ $statusCounts['inactive'] ?? 0 }}</div>
                            <div class="mt-1 text-xs font-semibold text-slate-600">Off</div>
                        </div>
                        <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                            <div class="text-xl font-bold text-slate-950">{{ $statusCounts['archived'] ?? 0 }}</div>
                            <div class="mt-1 text-xs font-semibold text-slate-600">Archived</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-rose-200 bg-rose-50/70 p-4 sm:flex sm:items-center sm:justify-between sm:gap-4">
            <div class="min-w-0">
                <div class="text-sm font-bold text-rose-950">Recurring annual touch-base dates</div>
                <p class="mt-1 break-words text-sm text-rose-800">Set birthday and fixed annual Email/SMS touches for a Contact Status audience, repeating for a defined number of years.</p>
            </div>
            <a
                href="{{ route('crm.campaigns.annual-touches.index') }}"
                class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-full bg-rose-900 px-5 text-sm font-bold text-white hover:bg-rose-800 sm:mt-0 sm:w-auto"
            >
                Annual touch-base dates
            </a>
        </section>

        @if(app()->environment('local'))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <div class="text-sm font-bold text-amber-950">Development testing</div>
                    <p class="mt-1 break-words text-sm text-amber-800">Use the Campaign Simulator to fake time and exercise the real MessageChain runtime without provider delivery.</p>
                </div>
                <a
                    href="{{ route('crm.campaigns.simulator.index') }}"
                    class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-full bg-amber-950 px-5 text-sm font-bold text-white hover:bg-amber-900 sm:mt-0 sm:w-auto"
                >
                    Open simulator
                </a>
            </section>
        @endif

        <section class="min-w-0 overflow-hidden rounded-3xl border border-rose-200 bg-white/95 shadow-sm">
            @forelse($campaigns as $campaign)
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
                @endphp

                <article class="min-w-0 border-b border-slate-200 p-4 last:border-b-0 sm:p-6">
                    <div class="flex min-w-0 flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <h3 class="min-w-0 break-words text-lg font-semibold text-slate-950">
                                    {{ $campaign->name }}
                                </h3>
                                <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset {{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>

                            @if($campaign->description)
                                <p class="mt-2 max-w-3xl break-words text-sm leading-6 text-slate-600">
                                    {{ $campaign->description }}
                                </p>
                            @endif

                            <div class="mt-4 flex min-w-0 flex-col gap-2 text-sm text-slate-600 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2">
                                <span class="break-words"><strong class="font-semibold text-slate-950">{{ $campaign->message_steps_count }}</strong> message {{ \Illuminate\Support\Str::plural('step', $campaign->message_steps_count) }}</span>
                                <span class="break-words"><strong class="font-semibold text-slate-950">{{ $campaign->open_enrollments_count }}</strong> current {{ \Illuminate\Support\Str::plural('participant', $campaign->open_enrollments_count) }}</span>
                            </div>
                        </div>

                        <a
                            href="{{ route('crm.campaigns.show', $campaign) }}"
                            class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                        >
                            Open campaign
                        </a>
                    </div>
                </article>
            @empty
                <div class="p-6 text-center sm:p-10">
                    <h3 class="break-words text-lg font-semibold text-slate-950">No campaigns are available yet.</h3>
                    <p class="mt-2 break-words text-sm text-slate-600">Create a campaign here or sync a preset-owned campaign to get started.</p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>