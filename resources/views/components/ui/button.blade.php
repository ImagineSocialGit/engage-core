@props([
    'href' => null,
    'variant' => 'primary',
    'type' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2';
    $variants = [
        'primary' => 'bg-slate-900 text-white hover:bg-slate-800 focus:ring-slate-900',
        'secondary' => 'bg-white text-slate-900 border border-slate-300 hover:bg-slate-50 focus:ring-slate-400',
        'ghost' => 'bg-transparent text-slate-700 hover:bg-slate-100 focus:ring-slate-400',
    ];
    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif