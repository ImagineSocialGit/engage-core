@php
    $junctionTarget = $junction['navigation_target'] ?? null;
    $alternativePath = is_array($junction['alternative_path'] ?? null)
        ? $junction['alternative_path']
        : null;
@endphp

<article
    data-process-highway-junction="{{ $junction['key'] }}"
    data-process-highway-junction-node="{{ $junction['node_key'] }}"
    @class([
        'grid items-start gap-5',
        'lg:grid-cols-[minmax(0,1fr)_minmax(17rem,0.62fr)]' => $alternativePath !== null,
    ])
>
    <div class="rounded-2xl p-4 shadow-sm ring-1 sm:p-5 {{ module_tone('inbound_messaging', 'panel') }}">
        <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ module_tone('inbound_messaging', 'badge') }}">Inbound Messaging</span>
        <h4 class="mt-3 text-base font-semibold leading-6 text-slate-950">{{ $junction['label'] }}</h4>
        <p class="mt-1 text-sm leading-5 text-slate-600">{{ $junction['description'] }}</p>

        @if(is_array($junctionTarget))
            <a href="{{ $junctionTarget['url'] }}" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                {{ $junctionTarget['label'] }}
            </a>
        @endif
    </div>

    @if($alternativePath !== null)
        <aside data-process-highway-alternative-reply="{{ $junction['key'] }}" aria-label="Other reply path">
            <div class="mb-2 hidden items-center lg:flex" aria-hidden="true">
                <span class="h-px w-5 bg-slate-300"></span>
                <svg viewBox="0 0 20 20" class="-ml-1 h-5 w-5 text-slate-400" fill="currentColor">
                    <path d="M7.5 5.25 12.25 10 7.5 14.75V5.25Z" />
                </svg>
                <span class="ml-1 text-[0.68rem] font-bold uppercase tracking-[0.12em] text-slate-500">Exits to…</span>
            </div>

            <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-950">{{ $alternativePath['label'] }}</p>
                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $alternativePath['description'] }}</p>

                <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-700">
                    @if($alternativePath['inbox_review'] ?? false)
                        <li>• The reply remains in the Inbound Messaging Inbox for review.</li>
                    @endif
                    @if($alternativePath['team_notification_available'] ?? false)
                        <li>• A notification is scheduled when an eligible assigned or default team member is available.</li>
                    @endif
                    @if($alternativePath['campaign_continues'] ?? false)
                        <li>• The campaign continues.</li>
                    @endif
                </ul>

                @if(is_string($alternativePath['recommendation'] ?? null))
                    <div data-process-highway-recommendation class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-amber-800">Suggested improvement</p>
                        <p class="mt-1 text-xs leading-5 text-amber-950">{{ $alternativePath['recommendation'] }}</p>
                    </div>
                @endif
            </div>
        </aside>
    @endif
</article>