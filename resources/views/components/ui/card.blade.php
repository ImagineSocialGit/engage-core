@props([
    'padding' => 'default',
])

@php
    $paddingClasses = [
        'none' => '',
        'sm' => 'p-4',
        'default' => 'p-6',
        'lg' => 'p-8',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm ' . ($paddingClasses[$padding] ?? $paddingClasses['default'])]) }}>
    {{ $slot }}
</div>