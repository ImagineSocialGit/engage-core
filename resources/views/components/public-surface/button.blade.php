@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
])

@if($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([
            'inline-flex min-h-11 items-center justify-center rounded-full px-5 py-2.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
            'bg-[var(--public-primary)] text-white hover:brightness-95 focus-visible:ring-[var(--public-accent)]' => $variant === 'primary',
            'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 focus-visible:ring-slate-400' => $variant === 'secondary',
            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-slate-400' => $variant === 'quiet',
        ]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([
            'inline-flex min-h-11 items-center justify-center rounded-full px-5 py-2.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45',
            'bg-[var(--public-primary)] text-white hover:brightness-95 focus-visible:ring-[var(--public-accent)]' => $variant === 'primary',
            'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 focus-visible:ring-slate-400' => $variant === 'secondary',
            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-slate-400' => $variant === 'quiet',
        ]) }}
    >
        {{ $slot }}
    </button>
@endif