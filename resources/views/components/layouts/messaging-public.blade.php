@props([
    'title' => null,
])

@php
    $brandName = config('app.name');
@endphp

<x-layouts.app :title="$title ?? $brandName">
    <div class="flex min-h-screen flex-col bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-5xl px-6 py-5 text-base font-semibold tracking-tight text-slate-950">
                {{ $brandName }}
            </div>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-5xl px-6 py-6 text-sm text-slate-500">
                {{ $brandName }}
            </div>
        </footer>
    </div>
</x-layouts.app>