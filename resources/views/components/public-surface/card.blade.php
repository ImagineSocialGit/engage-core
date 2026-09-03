@props([
    'padded' => true,
    'padding' => 'md',
])

<section {{ $attributes->class([
    config('public_surfaces.theme.components.card.base', 'rounded-[2rem] border border-slate-200/80 bg-[var(--public-surface)] text-slate-950 shadow-xl shadow-slate-200/50'),
    config('public_surfaces.theme.components.card.padding.'.$padding, 'p-5 sm:p-7 lg:p-8') => $padded,
]) }}>
    {{ $slot }}
</section>