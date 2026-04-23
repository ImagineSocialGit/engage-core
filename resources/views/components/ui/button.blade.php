@props([
    'href' => null,
    'variant' => 'primary',
    'type' => 'button',
    'size' => 'md',
])

@php
    $config = config('theme.webinar_public.components.button', []);

    $classes = trim(implode(' ', array_filter([
        $config['base'] ?? '',
        $config['sizes'][$size] ?? ($config['sizes']['md'] ?? ''),
        $config['variants'][$variant] ?? ($config['variants']['primary'] ?? ''),
    ])));
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