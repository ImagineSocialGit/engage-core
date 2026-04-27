@props([
    'for' => null,
])

@php
    $config = array_replace_recursive(
        config('webinars.style.components.label', []),
        config('webinars.register.style.components.label', []),
    );

    $classes = trim(
        $config['base']
        ?? 'mb-2 block text-sm font-extrabold tracking-tight text-ink'
    );
@endphp

<label
    @if($for) for="{{ $for }}" @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    {{ $slot }}
</label>