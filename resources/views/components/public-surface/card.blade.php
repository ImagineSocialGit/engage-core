@props([
    'padded' => true,
])

<section {{ $attributes->class([
    'rounded-[2rem] border border-slate-200/80 bg-[var(--public-surface)] shadow-xl shadow-slate-200/50',
    'p-5 sm:p-7 lg:p-8' => $padded,
]) }}>
    {{ $slot }}
</section>