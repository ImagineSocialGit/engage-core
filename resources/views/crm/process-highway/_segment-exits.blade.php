@php($acknowledgementChannels = $acknowledgements->flatMap(fn (array $acknowledgement): array => $acknowledgement['channels'] ?? [])->unique()->sort()->values())
@php($wideExitLayout = (bool) ($wideExitLayout ?? false))

<div @class(['grid gap-3', 'sm:grid-cols-2' => $wideExitLayout]) data-process-highway-exit-stack="{{ $segment['key'] }}">
    @foreach($segment['mechanism_outcomes'] ?? [] as $outcome)
        @include('crm.process-highway._outcome', [
            'outcome' => $outcome,
            'businessHighwayKey' => $businessHighwayKey,
        ])
    @endforeach

    @foreach($journeyNodesWithOutcomes as $node)
        @if(($segment['source_key'] ?? null) === 'campaigns')
            @foreach($node['outcomes'] as $outcome)
                @include('crm.process-highway._outcome', [
                    'outcome' => $outcome,
                    'businessHighwayKey' => $businessHighwayKey,
                ])
            @endforeach
        @else
            <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                <p class="text-xs font-semibold text-slate-600">{{ $node['label'] }}</p>
                <div class="mt-2 space-y-2">
                    @foreach($node['outcomes'] as $outcome)
                        @include('crm.process-highway._outcome', [
                            'outcome' => $outcome,
                            'businessHighwayKey' => $businessHighwayKey,
                            'compact' => true,
                        ])
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    @foreach($segment['additional_outcome_groups'] ?? [] as $group)
        <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
            <p class="text-xs font-semibold text-slate-600">{{ $group['trigger_node']['label'] }}</p>
            <div class="mt-2 space-y-2">
                @foreach($group['outcomes'] as $outcome)
                    @include('crm.process-highway._outcome', [
                        'outcome' => $outcome,
                        'businessHighwayKey' => $businessHighwayKey,
                        'compact' => true,
                    ])
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
</div>