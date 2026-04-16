@props([
    'type' => 'text',
])

<input
    type="{{ $type }}"
    {{ $attributes->merge(['class' => 'block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-slate-900 placeholder-slate-400 shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900']) }}
>