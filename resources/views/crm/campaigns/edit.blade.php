<x-layouts.crm
    :title="$campaign->name.' setup'"
    heading="Campaign setup"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="min-w-0 space-y-6">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('crm.campaigns.show', $campaign) }}" class="break-words text-sm font-semibold text-slate-600 hover:text-slate-950">
                &larr; Campaign overview
            </a>

            <a
                href="{{ route('crm.campaigns.message-templates.index', ['campaign' => $campaign->getKey()]) }}"
                class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
            >
                Review messages
            </a>
        </div>

        <x-campaigns.builder-shell :stages="$workspace['builder_stages']" mode="edit">
            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">1 · Start</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">What makes this campaign start?</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Read only</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        Existing campaigns keep their current start behavior. Start rules are not changed from this screen yet.
                    </p>
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">2 · Schedule</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Current schedule</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Summary</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $workspace['message_step_count'] }} active message {{ \Illuminate\Support\Str::plural('step', $workspace['message_step_count']) }} currently define the campaign timeline.
                    </p>
                    @if($workspace['channels'] !== [])
                        <div class="mt-4 flex min-w-0 flex-wrap gap-2">
                            @foreach($workspace['channels'] as $channel)
                                <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-800 ring-1 ring-inset ring-rose-200">
                                    {{ strtoupper($channel) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">3 · Messages</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Review the messages</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Available</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $workspace['message_count'] }} active {{ \Illuminate\Support\Str::plural('message', $workspace['message_count']) }} are currently available across the campaign schedule.
                    </p>
                    <a
                        href="{{ route('crm.campaigns.message-templates.index', ['campaign' => $campaign->getKey()]) }}"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                    >
                        Review messages
                    </a>
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">4 · Review</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Confirm before going live</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Summary</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        This campaign is currently <strong class="font-semibold text-slate-950">{{ $campaign->isActive() ? 'active' : 'off' }}</strong>. Activation remains controlled from the campaign overview while the guided review step is built out.
                    </p>
                    <a
                        href="{{ route('crm.campaigns.show', $campaign) }}"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        View campaign overview
                    </a>
                </section>
            </div>
        </x-campaigns.builder-shell>
    </div>
</x-layouts.crm>