@props([
    'type' => 'text',
    'value' => null,
])

@php
    $config = config('theme.webinar_public.components.input', []);

    $classes = trim(implode(' ', array_filter([
        $config['base'] ?? '',
    ])));
@endphp

<input
    type="{{ $type }}"
    value="{{ $value }}"
    {{ $attributes->merge(['class' => $classes]) }}
>