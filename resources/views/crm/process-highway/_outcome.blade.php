@php($outcomeTarget = $outcome['node']['navigation_target'] ?? null)
@php($compact = $compact ?? false)

@if(is_array($outcomeTarget))
    <a
        href="{{ $outcomeTarget['url'] }}"
        data-process-highway-outcome="{{ $outcome['node']['key'] }}"
        @class([
            'block bg-white transition hover:shadow-sm',
            'rounded-lg px-3 py-2 ring-1 ring-slate-200 hover:ring-slate-300' => $compact,
            'rounded-xl border border-slate-200 px-3 py-3 hover:border-slate-300' => ! $compact,
        ])
    >
        <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">{{ $outcome['edge']['label'] ?: 'Can lead to' }}</span>
        <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
    </a>
@else
    <div
        data-process-highway-outcome="{{ $outcome['node']['key'] }}"
        @class([
            'block bg-white',
            'rounded-lg px-3 py-2 ring-1 ring-slate-200' => $compact,
            'rounded-xl border border-slate-200 px-3 py-3' => ! $compact,
        ])
    >
        <span class="block text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500">{{ $outcome['edge']['label'] ?: 'Can lead to' }}</span>
        <span class="mt-1 block text-sm font-semibold text-slate-900">{{ $outcome['node']['label'] }}</span>
    </div>
@endif