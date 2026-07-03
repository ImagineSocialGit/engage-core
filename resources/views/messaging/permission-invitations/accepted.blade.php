<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    @if($content['meta_description'] ?? null)
        <meta name="description" content="{{ $content['meta_description'] }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    <main class="min-h-screen {{ $style['section'] ?? 'bg-white text-slate-900' }}">
        <section>
            <div class="{{ $style['inner'] ?? 'mx-auto w-full max-w-3xl px-6 py-16 sm:py-24' }}">
                <div class="{{ $style['card'] ?? 'rounded-3xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8' }}">
                    @if (session('success'))
                        <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">
                            {{ session('success') }}
                        </div>
                    @endif

                    <div class="{{ $style['eyebrow'] ?? 'text-sm font-bold uppercase tracking-wide text-slate-500' }}">
                        {{ $content['eyebrow'] ?? 'Communication Preferences' }}
                    </div>

                    <h1 class="{{ $style['heading'] ?? 'mt-3 text-4xl font-extrabold tracking-tight text-slate-900' }}">
                        {{ $content['accepted_heading'] ?? 'Your preferences are confirmed.' }}
                    </h1>

                    <p class="{{ $style['body'] ?? 'mt-5 text-lg leading-8 text-slate-600' }}">
                        {{ $content['accepted_body'] ?? 'Thank you. Your communication preferences have been saved.' }}
                    </p>

                    @if($invitation->accepted_channels)
                        <div class="mt-6 rounded-2xl border border-black/10 bg-white p-4 text-sm text-slate-700">
                            Confirmed channels:
                            <span class="font-bold text-slate-950">
                                {{ collect($invitation->accepted_channels)->map(fn ($channel) => strtoupper($channel))->join(', ') }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </main>
</body>
</html>
