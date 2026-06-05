<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? config('app.name') }}</title>

    @if(!empty($metaDescription ?? null))
        <meta name="description" content="{{ $metaDescription }}">
    @endif

    <x-layouts.favicons />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    @auth
        @if(session('success'))
            <div class="fixed top-4 right-4 z-50 max-w-sm w-full">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow">
                    {{ session('success') }}
                </div>
            </div>
        @endif
    @endauth
    {{ $slot }}
</body>
</html>