@props([
    'path',
    'alt' => '',
    'sizes' => '100vw',
])

@php
    $base = cdn_image($path);

    $widths = [320, 640, 960, 1280, 1600];

    $avifSrcset = collect($widths)
        ->map(fn ($w) => "{$base}/{$w}.avif {$w}w")
        ->implode(', ');

    $webpSrcset = collect($widths)
        ->map(fn ($w) => "{$base}/{$w}.webp {$w}w")
        ->implode(', ');

    $placeholder = "{$base}/placeholder.webp";
@endphp

<picture x-data="{ loaded: false }">
    <source
        type="image/avif"
        srcset="{{ $avifSrcset }}"
        sizes="{{ $sizes }}"
    >

    <source
        type="image/webp"
        srcset="{{ $webpSrcset }}"
        sizes="{{ $sizes }}"
    >

    <img
        src="{{ $placeholder }}"
        alt="{{ $alt }}"
        loading="lazy"
        @load="loaded = true"
        {{ $attributes->class([
            'transition-opacity duration-500',
            'opacity-0' => '!loaded',
            'opacity-100' => 'loaded',
        ]) }}
    >
</picture>