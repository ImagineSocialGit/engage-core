@props([
    'padding' => 'md',
])

@php
    $config = array_replace_recursive(
        config('webinars.style.components.card', []),
        config('webinars.register.style.components.card', []),
    );

    $classes = trim(implode(' ', array_filter([
        $config['base'] ?? 'rounded-3xl border border-black/10 bg-white text-ink shadow-2xl shadow-black/15',
        $config['padding'][$padding] ?? ($config['padding']['md'] ?? 'p-6 sm:p-8'),
    ])));
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>