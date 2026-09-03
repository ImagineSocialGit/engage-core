@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
])

@if($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([
            config('public_surfaces.theme.components.button.base', 'inline-flex min-h-11 items-center justify-center rounded-full px-5 py-2.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2'),
            config('public_surfaces.theme.components.button.variants.'.$variant, 'bg-[var(--public-primary)] text-white hover:brightness-95 focus-visible:ring-[var(--public-accent)]'),
        ]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([
            config('public_surfaces.theme.components.button.base', 'inline-flex min-h-11 items-center justify-center rounded-full px-5 py-2.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45'),
            config('public_surfaces.theme.components.button.variants.'.$variant, 'bg-[var(--public-primary)] text-white hover:brightness-95 focus-visible:ring-[var(--public-accent)]'),
        ]) }}
    >
        {{ $slot }}
    </button>
@endif