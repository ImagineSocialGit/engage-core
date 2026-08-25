@php($segmentTarget = $segment['navigation_target'] ?? null)
@php($mechanismBadge = $mechanismBadges[$segment['source_key']] ?? null)
@php($journeyNodesWithOutcomes = collect($segment['journey_nodes'] ?? [])->filter(fn (array $node): bool => ($node['outcomes'] ?? []) !== []))
@php($journeyNodesWithoutOutcomes = collect($segment['journey_nodes'] ?? [])->reject(fn (array $node): bool => ($node['outcomes'] ?? []) !== []))
@php($acknowledgements = collect($segment['supporting_acknowledgements'] ?? []))
@php($acknowledgementChannels = $acknowledgements->flatMap(fn (array $acknowledgement): array => $acknowledgement['channels'] ?? [])->unique()->sort()->values())
@php($hasAttachedOutcomes = ($segment['mechanism_outcomes'] ?? []) !== [] || $journeyNodesWithOutcomes->isNotEmpty() || ($segment['additional_outcome_groups'] ?? []) !== [] || $acknowledgements->isNotEmpty())

<article
    data-process-highway-segment="{{ $segment['key'] }}"
    data-process-highway-owner="{{ $segment['source_key'] }}"
    @if($mechanismBadge !== null) data-process-highway-mechanism="{{ $segment['source_key'] }}" @endif
    class="rounded-2xl p-4 ring-1 sm:p-5 {{ module_tone($segment['source_key'], 'panel') }}"
>
    <div @class([
        'grid gap-5',
        'lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.72fr)]' => $hasAttachedOutcomes,
    ])>
        <div class="min-w-0">
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

        @if($hasAttachedOutcomes)
            <aside class="space-y-3 lg:border-l lg:border-black/10 lg:pl-5" aria-label="What this can cause">
                @foreach($segment['mechanism_outcomes'] ?? [] as $outcome)
                    @include('crm.process-highway._outcome', ['outcome' => $outcome])
                @endforeach

                @foreach($journeyNodesWithOutcomes as $node)
                    <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                        <p class="text-xs font-semibold text-slate-600">{{ $node['label'] }}</p>
                        <div class="mt-2 space-y-2">
                            @foreach($node['outcomes'] as $outcome)
                                @include('crm.process-highway._outcome', ['outcome' => $outcome, 'compact' => true])
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @foreach($segment['additional_outcome_groups'] ?? [] as $group)
                    <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                        <p class="text-xs font-semibold text-slate-600">{{ $group['trigger_node']['label'] }}</p>
                        <div class="mt-2 space-y-2">
                            @foreach($group['outcomes'] as $outcome)
                                @include('crm.process-highway._outcome', ['outcome' => $outcome, 'compact' => true])
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if($acknowledgements->isNotEmpty())
                    <div data-process-highway-acknowledgement class="rounded-xl border border-blue-200 bg-blue-50 p-3">
                        <p class="text-xs font-bold uppercase tracking-[0.1em] text-blue-700">For the same matched reply</p>
                        <p class="mt-1 text-sm font-semibold text-blue-950">An acknowledgement is sent on the channel the contact used.</p>
                        @if($acknowledgementChannels->isNotEmpty())
                            <p class="mt-1 text-xs text-blue-800">Configured for {{ $acknowledgementChannels->map(fn (string $channel): string => strtoupper($channel))->implode(' and ') }}.</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($acknowledgements as $acknowledgement)
                                @php($acknowledgementTarget = $acknowledgement['navigation_target'] ?? null)
                                @php($channelLabel = collect($acknowledgement['channels'] ?? [])->map(fn (string $channel): string => strtoupper($channel))->implode('/'))
                                @if(is_array($acknowledgementTarget))
                                    <a href="{{ $acknowledgementTarget['url'] }}" class="text-xs font-semibold text-blue-900 underline decoration-blue-300 underline-offset-4 hover:text-blue-950">
                                        Edit {{ $channelLabel ?: 'acknowledgement' }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </aside>
        @endif
    </div>
</article>