@props([
    'asset',
    'alt' => '',
    'loading' => 'lazy',
])

@if($asset->hasProgressiveImageVariants() && $asset->imageVariantUrl('medium') && $asset->imageVariantUrl('default'))
    <div
        x-data="{ loaded: false }"
        x-init="
            loaded = $refs.full?.complete && $refs.full?.naturalWidth > 0;
            $nextTick(() => {
                if ($refs.full?.complete && $refs.full?.naturalWidth > 0) {
                    loaded = true;
                }
            });
        "
        class="relative block overflow-hidden"
        data-media-progressive-image
    >
        <img
            src="{{ $asset->imageVariantUrl('medium') }}"
            alt=""
            aria-hidden="true"
            loading="{{ $loading }}"
            decoding="async"
            :class="{ 'opacity-0': loaded, 'opacity-100': ! loaded }"
            class="absolute inset-0 h-full w-full scale-[1.02] object-cover blur-sm transition-opacity duration-500"
        >

        <img
            x-ref="full"
            src="{{ $asset->imageVariantUrl('default') }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            decoding="async"
            @load="loaded = true"
            :class="{ 'opacity-100': loaded, 'opacity-0': ! loaded }"
            {{ $attributes->class([
                'relative z-10 transition-opacity duration-500',
            ]) }}
        >
    </div>
@elseif($asset->publicUrl())
    <img
        src="{{ $asset->publicUrl() }}"
        alt="{{ $alt }}"
        loading="{{ $loading }}"
        decoding="async"
        data-media-original-image
        {{ $attributes }}
    >
@endif