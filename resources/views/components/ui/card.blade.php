@props([
    'padding' => 'lg',
])

@php
    $config = config('theme.webinar_public.components.card', []);

    $classes = trim(implode(' ', array_filter([
        $config['base'] ?? '',
        $config['padding'][$padding] ?? ($config['padding']['lg'] ?? ''),
    ])));
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>