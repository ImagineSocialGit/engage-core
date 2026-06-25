@props([
    'id' => null,
])

<select
    @if($id)
        id="{{ $id }}"
    @endif

    {{ $attributes->merge([
        'class' => 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-500',
    ]) }}
>
    {{ $slot }}
</select>