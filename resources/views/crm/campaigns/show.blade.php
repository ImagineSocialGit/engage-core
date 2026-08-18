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
                    </div>

                    @if($campaign->description)
                        <p class="mt-3 max-w-3xl break-words text-sm leading-6 text-slate-600">
                            {{ $campaign->description }}
                        </p>
                    @endif
                </div>

                <div class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-wrap">
                    <a
                        href="{{ route('crm.campaigns.edit', $campaign) }}"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        Review setup
                    </a>

                    @if($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.deactivate', $campaign) }}"
                            class="w-full sm:w-auto"
                            onsubmit="return confirm('Turn this Campaign off? Current open enrollments will be cancelled and pending Campaign messages will be skipped.');"
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

        <section class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['active_enrollment_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Current participants</div>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['pending_message_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Messages waiting to send</div>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['message_step_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Message steps</div>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="text-2xl font-bold text-slate-950">{{ $workspace['message_count'] }}</div>
                <div class="mt-1 break-words text-sm font-semibold text-slate-600">Messages</div>
            </div>
        </section>

        <section class="min-w-0 rounded-3xl border border-rose-200 bg-white/95 p-4 shadow-sm sm:p-8">
            <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Campaign setup</p>
                    <h2 class="mt-2 break-words text-xl font-semibold tracking-tight text-slate-950">Review the campaign from start through activation</h2>
                    <p class="mt-2 max-w-3xl break-words text-sm leading-6 text-slate-600">
                        The setup workspace uses the same structure for reviewing an existing campaign and, later, for creating one from a copy or from scratch.
                    </p>
                </div>

                <a
                    href="{{ route('crm.campaigns.edit', $campaign) }}"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                >
                    Open setup
                </a>
            </div>
        </section>

        @if($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
            <section class="break-words rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950 sm:p-5">
                Turning this campaign off is an operational stop: current open enrollments are cancelled and pending Campaign messages are skipped. Turning it back on later allows future enrollments but does not restart cancelled journeys.
            </section>
        @endif
    </div>
</x-layouts.crm>