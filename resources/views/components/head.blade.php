<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- BASIC SEO --}}
    @php
        $pageTitle = $metaData->title ?? ($title ?? config('app.name'));
        $fullTitle = $pageTitle . ' | ' . config('app.name');

        $description = $metaData->description ?? 'Default site description';
        $url = url()->current();
        $image = $metaData->image ?? asset('default-og.jpg');
    @endphp

    <title>{{ $fullTitle }}</title>
    <meta name="title" content="{{ $fullTitle }}">
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $url }}">

    {{-- OPEN GRAPH (Facebook, LinkedIn, etc.) --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $image }}">

    {{-- TWITTER --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">

    {{-- FAVICONS --}}
    @if(isset($favicon))
        @if($favicon->favicon_96x96)
            <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('storage/' . $favicon->favicon_96x96) }}" />
        @endif

        @if($favicon->favicon_svg)
            <link rel="icon" type="image/svg+xml" href="{{ asset('storage/' . $favicon->favicon_svg) }}" />
        @endif

        @if($favicon->favicon_icon)
            <link rel="shortcut icon" href="{{ asset('storage/' . $favicon->favicon_icon) }}" />
        @endif

        @if($favicon->apple_touch_icon)
            <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('storage/' . $favicon->apple_touch_icon) }}" />
        @endif

        @if($favicon->site_manifest)
            <link rel="manifest" href="{{ asset('storage/' . $favicon->site_manifest) }}" />
        @endif
    @endif

    {{-- DESIGN TOKENS --}}
    <style>
        @if (isset($siteData->siteColors))
        :root {
            --color-primary: {{ $siteData->siteColors['primary'] ?? 'var(--color-one)' }};
            --color-secondary: {{ $siteData->siteColors['secondary'] ?? 'var(--color-two)' }};
            --color-tertiary: {{ $siteData->siteColors['tertiary'] ?? 'var(--color-three)' }};
            --color-alt-black: {{ $siteData->siteColors['alt-black'] ?? 'var(--color-new-black)' }};
            --color-alt-white: {{ $siteData->siteColors['alt-white'] ?? 'var(--color-new-white)' }};
        }
        @endif

        html {
            scroll-behavior: smooth;
        }

        [x-cloak] { display: none !important; }
    </style>

    {{-- ASSETS --}}
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>