@props([
    'panel' => [],
    'item' => [],
    'module' => 'core',
    'targetRef' => '',
])

@php
    $tone = module_tone($module);
    $itemBaseClass = 'rounded-2xl p-4 ring-1 transition duration-500';
    $itemFocusClass = 'scale-[1.005] '.module_tone($module, 'item_focus');
    $itemRestClass = '';
    $badgeClasses = [
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
        'emerald' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];
    $href = $item['href'] ?? null;

    if (($panel['key'] ?? null) === 'tasks.today' && filled($href)) {
        $href .= str_contains((string) $href, '?') ? '&activity_tab=tasks' : '?activity_tab=tasks';
    }

    $acknowledgeTypes = [
        \App\Models\DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
        \App\Models\DashboardAcknowledgement::TYPE_WEBINAR_REGISTRATION,
    ];
@endphp

<div
    class="{{ $itemBaseClass }} {{ module_tone($module, 'item') }}"
    :class="focusedPanel === @js($targetRef) ? @js($itemFocusClass) : @js($itemRestClass)"
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $badgeClasses[$item['tone'] ?? 'slate'] ?? ($tone['badge'] ?? $badgeClasses['slate']) }}">
                {{ $item['label'] ?? 'Item' }}
            </span>

            <h3 class="mt-3 font-semibold text-slate-950">
                {{ $item['title'] ?? 'Untitled item' }}
            </h3>

            @if(filled($item['subtitle'] ?? null))
                <p class="mt-1 text-sm text-slate-600">
                    {{ $item['subtitle'] }}
                </p>
            @endif

            @if(filled($item['description'] ?? null))
                <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                    {{ $item['description'] }}
                </p>
            @endif
        </div>

        <div class="flex shrink-0 flex-col items-end gap-2">
            @if(filled($href))
                <a href="{{ $href }}" class="text-xs font-bold text-slate-700 underline underline-offset-4 hover:text-slate-950">
                    {{ $item['action_label'] ?? 'Open' }}
                </a>
            @endif

            @if(in_array($item['type'] ?? null, $acknowledgeTypes, true))
                <form method="POST" action="{{ route('crm.dashboard.acknowledgements.store') }}">
                    @csrf
                    <input type="hidden" name="item_type" value="{{ $item['type'] }}">
                    <input type="hidden" name="item_key" value="{{ $item['key'] }}">
                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                    <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-4 hover:text-slate-800">
                        {{ ($panel['key'] ?? null) === 'webinars.activity' ? 'Hide' : 'Clear' }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
