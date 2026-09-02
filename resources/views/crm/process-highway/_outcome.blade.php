@php($factTarget = $outcome['fact_target'] ?? null)
@php($outcomeTarget = is_array($factTarget) ? $factTarget : ($outcome['node']['navigation_target'] ?? null))
@php($compact = $compact ?? false)
@php($exitAnchor = is_string($outcome['exit_anchor'] ?? null) ? $outcome['exit_anchor'] : null)
@php($exitEdgeKey = is_string($outcome['edge']['key'] ?? null) ? $outcome['edge']['key'] : null)
@php($businessHighwayKey = is_string($businessHighwayKey ?? null) ? $businessHighwayKey : '')
@php($outcomeDetail = is_string($outcome['node']['detail'] ?? null) ? trim($outcome['node']['detail']) : '')

@if(is_array($outcomeTarget))
    <a
        href="{{ $outcomeTarget['url'] }}"
        @if($exitAnchor !== null) id="{{ $exitAnchor }}" tabindex="-1" @endif
        data-process-highway-outcome="{{ $outcome['node']['key'] }}"
        @if($exitAnchor !== null) data-process-highway-exit-anchor="{{ $exitAnchor }}" @endif
        @if($exitEdgeKey !== null) data-process-highway-exit-edge="{{ $exitEdgeKey }}" @endif
        data-process-highway-exit-highway="{{ $businessHighwayKey }}"
        @if(is_array($factTarget)) data-process-highway-fact-target="{{ $factTarget['criterion_key'] }}:{{ $factTarget['value'] }}" @endif
        @if($exitAnchor !== null) x-bind:class="focusedExit === @js($exitAnchor) ? 'ring-4 ring-orange-400 ring-offset-4' : ''" @endif
        @class([
            'block bg-white transition duration-300 hover:shadow-sm',
            'rounded-lg px-3 py-2 ring-1 ring-slate-200 hover:ring-slate-300' => $compact,
            'rounded-xl border border-slate-200 px-3 py-3 hover:border-slate-300' => ! $compact,
        ])
    >
        <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">{{ $outcome['edge']['label'] ?: 'Can lead to' }}</span>
        <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
        @if($outcomeDetail !== '')
            <span data-process-highway-outcome-detail class="mt-1 block text-xs leading-5 text-slate-600">{{ $outcomeDetail }}</span>
        @endif
    </a>
@else
    <div
        @if($exitAnchor !== null) id="{{ $exitAnchor }}" tabindex="-1" @endif
        data-process-highway-outcome="{{ $outcome['node']['key'] }}"
        @if($exitAnchor !== null) data-process-highway-exit-anchor="{{ $exitAnchor }}" @endif
        @if($exitEdgeKey !== null) data-process-highway-exit-edge="{{ $exitEdgeKey }}" @endif
        data-process-highway-exit-highway="{{ $businessHighwayKey }}"
        @if($exitAnchor !== null) x-bind:class="focusedExit === @js($exitAnchor) ? 'ring-4 ring-orange-400 ring-offset-4' : ''" @endif
        @class([
            'block bg-white transition duration-300',
            'rounded-lg px-3 py-2 ring-1 ring-slate-200' => $compact,
            'rounded-xl border border-slate-200 px-3 py-3' => ! $compact,
        ])
    >
        <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">{{ $outcome['edge']['label'] ?: 'Can lead to' }}</span>
        <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
        @if($outcomeDetail !== '')
            <span data-process-highway-outcome-detail class="mt-1 block text-xs leading-5 text-slate-600">{{ $outcomeDetail }}</span>
        @endif
    </div>
@endif