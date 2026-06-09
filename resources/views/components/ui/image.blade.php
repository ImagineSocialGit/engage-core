@props([
    'path',
    'alt' => '',
    'sizes' => '100vw',
    'loading' => 'lazy',
    'placeholder' => true,
])

@php
    $image = is_array($path) ? $path : [
        'path' => $path,
        'sizes' => [320, 640, 960, 1280, 1600],
        'placeholder' => "{$path}/placeholder.webp",
    ];

    $imagePath = trim($image['path'], '/');
    $widths = $image['sizes'] ?? [320, 640, 960, 1280, 1600];

    $cdn = rtrim(config('filesystems.disks.spaces.url'), '/');
    $clientKey = trim((string) config('app.client_key', env('CLIENT_KEY')), '/');

    if ($clientKey === '') {
        throw new RuntimeException('CLIENT_KEY is not configured.');
    }

    $base = "{$cdn}/{$clientKey}/images/{$imagePath}";

    $avifSrcset = collect($widths)
        ->map(fn ($width) => "{$base}/{$width}.avif {$width}w")
        ->implode(', ');

    $webpSrcset = collect($widths)
        ->map(fn ($width) => "{$base}/{$width}.webp {$width}w")
        ->implode(', ');

    $fallbackWidth = collect($widths)->contains(960)
        ? 960
        : collect($widths)->max();

    $placeholderPath = trim($image['placeholder'] ?? "{$imagePath}/placeholder.webp", '/');
    $placeholderUrl = "{$cdn}/{$clientKey}/images/{$placeholderPath}";
@endphp

<div
    x-data="{ loaded: false }"
    x-init="
        loaded = $refs.image?.complete && $refs.image?.naturalWidth > 0;
        $nextTick(() => {
            if ($refs.image?.complete && $refs.image?.naturalWidth > 0) {
                loaded = true;
            }
        });
    "
    class="relative block overflow-hidden"
>

    @if($placeholder)
        <img
            src="{{ $placeholderUrl }}"
            alt=""
            aria-hidden="true"
            :class="{ 'opacity-0': loaded, 'opacity-100': ! loaded }"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
        >
    @endif

    <picture class="block">

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
            x-ref="image"
            src="{{ "{$base}/{$fallbackWidth}.webp" }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            @load="loaded = true"
            :class="{ 'opacity-100': loaded, 'opacity-0': ! loaded }"
            {{ $attributes->class([
                'relative z-10 transition-opacity duration-500',
            ]) }}
        >

    </picture>

</div>