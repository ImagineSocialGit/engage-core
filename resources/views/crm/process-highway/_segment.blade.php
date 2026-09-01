@php($segmentTarget = $segment['navigation_target'] ?? null)
@php($mechanismBadge = $mechanismBadges[$segment['source_key']] ?? null)
@php($journeyNodesWithOutcomes = collect($segment['journey_nodes'] ?? [])->filter(fn (array $node): bool => ($node['outcomes'] ?? []) !== []))
@php($journeyNodesWithoutOutcomes = collect($segment['journey_nodes'] ?? [])->reject(fn (array $node): bool => ($node['outcomes'] ?? []) !== []))
@php($acknowledgements = collect($segment['supporting_acknowledgements'] ?? []))
@php($hasAttachedOutcomes = ($segment['mechanism_outcomes'] ?? []) !== [] || $journeyNodesWithOutcomes->isNotEmpty() || ($segment['additional_outcome_groups'] ?? []) !== [] || $acknowledgements->isNotEmpty())
@php($isTerminalSegment = (bool) ($isTerminalSegment ?? false))

<article
    data-process-highway-segment="{{ $segment['key'] }}"
    data-process-highway-owner="{{ $segment['source_key'] }}"
    data-process-highway-terminal-segment="{{ $isTerminalSegment ? 'true' : 'false' }}"
    @if($mechanismBadge !== null) data-process-highway-mechanism="{{ $segment['source_key'] }}" @endif
>
    <div @class([
        'grid items-start gap-5',
        'lg:grid-cols-[minmax(0,1fr)_minmax(17rem,0.62fr)]' => $hasAttachedOutcomes && ! $isTerminalSegment,
    ])>
        <div
            data-process-highway-road-node="{{ $segment['key'] }}"
            class="rounded-2xl p-4 shadow-sm ring-1 sm:p-5 {{ module_tone($segment['source_key'], 'panel') }}"
        >
            <div class="flex flex-wrap items-center gap-2">
                @if($mechanismBadge !== null)
                    <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ module_tone($segment['source_key'], 'badge') }}">{{ $mechanismBadge }}</span>
                @endif
                @if($segment['state'] !== 'active')
                    <span class="text-xs font-semibold text-slate-600">{{ $segment['state_label'] }}</span>
                @endif
            </div>

            <h4 class="mt-2 text-base font-semibold leading-6 text-slate-950">{{ $segment['name'] }}</h4>
            @if($segment['description'])
                <p class="mt-1 text-sm leading-5 text-slate-600">{{ $segment['description'] }}</p>
            @endif

            @if(is_array($segmentTarget))
                <a href="{{ $segmentTarget['url'] }}" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    {{ $segmentTarget['label'] }}
                </a>
            @endif

            @if($journeyNodesWithoutOutcomes->isNotEmpty())
                <details class="mt-4 border-t border-black/5 pt-3">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-700">More details</summary>
                    <ul class="mt-3 space-y-2">
                        @foreach($journeyNodesWithoutOutcomes as $node)
                            @php($nodeTarget = $node['navigation_target'] ?? null)
                            <li>
                                @if(is_array($nodeTarget))
                                    <a href="{{ $nodeTarget['url'] }}" class="text-sm font-medium text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">{{ $node['label'] }}</a>
                                @else
                                    <span class="text-sm font-medium text-slate-700">{{ $node['label'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        @if($hasAttachedOutcomes && ! $isTerminalSegment)
            <aside data-process-highway-side-exits="{{ $segment['key'] }}" aria-label="Side exits">
                <div class="mb-2 hidden items-center lg:flex" aria-hidden="true">
                    <span class="h-px w-5 bg-slate-300"></span>
                    <svg viewBox="0 0 20 20" class="-ml-1 h-5 w-5 text-slate-400" fill="currentColor">
                        <path d="M7.5 5.25 12.25 10 7.5 14.75V5.25Z" />
                    </svg>
                    <span class="ml-1 text-[0.68rem] font-bold uppercase tracking-[0.12em] text-slate-500">Side exits</span>
                </div>

                @include('crm.process-highway._segment-exits', [
                    'segment' => $segment,
                    'businessHighwayKey' => $businessHighwayKey,
                    'journeyNodesWithOutcomes' => $journeyNodesWithOutcomes,
                    'acknowledgements' => $acknowledgements,
                    'wideExitLayout' => false,
                ])
            </aside>
        @endif
    </div>

    @if($hasAttachedOutcomes && $isTerminalSegment)
        <aside data-process-highway-terminal-exits="{{ $segment['key'] }}" aria-label="Final exits" class="mt-3">
            <div class="flex flex-col items-center" aria-hidden="true">
                <span class="h-6 w-px bg-slate-300"></span>
                <svg viewBox="0 0 20 20" class="-mt-1 h-5 w-5 text-slate-400" fill="currentColor">
                    <path d="M5.25 7.5 10 12.25 14.75 7.5H5.25Z" />
                </svg>
            </div>
            <p class="mb-3 text-center text-[0.68rem] font-bold uppercase tracking-[0.12em] text-slate-500">Final exits</p>
            <div class="mx-auto max-w-4xl">
                @include('crm.process-highway._segment-exits', [
                    'segment' => $segment,
                    'businessHighwayKey' => $businessHighwayKey,
                    'journeyNodesWithOutcomes' => $journeyNodesWithOutcomes,
                    'acknowledgements' => $acknowledgements,
                    'wideExitLayout' => true,
                ])
            </div>
        </aside>
    @endif
</article>