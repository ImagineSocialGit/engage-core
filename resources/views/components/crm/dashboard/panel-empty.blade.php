@props([
    'panel',
    'layout' => 'work',
])

@php
    $panel = is_array($panel) ? $panel : [];
    $wrapperClass = $layout === 'context'
        ? 'rounded-2xl border border-dashed border-slate-200 p-6 lg:col-span-2'
        : 'rounded-2xl border border-dashed border-slate-200 p-6 text-center';
@endphp

<div class="{{ $wrapperClass }}">
    <p class="font-semibold text-slate-950">
        {{ $panel['empty_title'] ?? ($layout === 'context' ? 'Nothing new here.' : 'Nothing needs attention here.') }}
    </p>
    <p class="mt-1 text-sm text-slate-500">
        {{ $panel['empty_description'] ?? ($layout === 'context' ? 'This context panel will appear when there is something useful to review.' : 'This area is clear.') }}
    </p>
</div>
