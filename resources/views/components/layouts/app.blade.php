@props([
    'title' => null,
    'metaDescription' => null,
    'robots' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? config('app.name') }}</title>

    @if(!empty($metaDescription ?? null))
        <meta name="description" content="{{ $metaDescription }}">
    @endif

    @if(!empty($robots ?? null))
        <meta name="robots" content="{{ $robots }}">
    @endif

    <x-layouts.favicons />

    <style>
    :root {
        --brand-primary: {{ config('theme.colors.primary', '#FF1ECE') }};
        --brand-primary-light: {{ config('theme.colors.primary_light', '#FF4AD8') }};
        --brand-secondary: {{ config('theme.colors.secondary', '#0F0F0F') }};
        --brand-ink: {{ config('theme.colors.ink', '#111111') }};
        --brand-soft: {{ config('theme.colors.soft', '#FAFAFA') }};

        --font-sans-family: "{{ config('theme.fonts.sans', 'Instrument Sans') }}";
    }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    @auth
        @if(session('success'))
            <div class="fixed inset-x-4 top-4 z-50 sm:left-auto sm:right-4 sm:w-full sm:max-w-sm" role="status" aria-live="polite">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow">
                    {{ session('success') }}
                </div>
            </div>
        @endif
    @endauth
    {{ $slot }}
</body>
</html>