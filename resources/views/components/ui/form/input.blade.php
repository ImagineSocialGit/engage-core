@props([
    'type' => 'text',
])

@php
    $config = array_replace_recursive(
        config('webinars.style.components.input', []),
        config('webinars.register.style.components.input', []),
    );

    $classes = trim($config['base']
        ?? 'block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-ink');
@endphp

<input
    type="{{ $type }}"
    {{ $attributes->merge([
        'class' => $classes,
    ]) }}
>