@props([
    'brandName' => config('app.name'),
    'brandLogo' => null,
    'brandLogoUrl' => null,
    'brandAlt' => null,
    'brandSizes' => null,
    'brandHref' => '/',
    'surfaceLabel' => null,
    'surfaceHref' => null,
])

<div
    data-public-surface-header
    x-data="{ compactLogo: false }"
    x-init="window.addEventListener('scroll', () => {
        compactLogo = window.scrollY > 60;
    }, { passive: true });"
    class="{{ config('public_surfaces.theme.components.header.inner', 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4') }}"
>
    @if($brandLogo)
        <a
            href="{{ $brandHref }}"
            aria-label="{{ $brandName }}"
            data-public-surface-brand
            class="transition-all"
            :class="compactLogo
                ? @js(config('public_surfaces.theme.components.header.brand_link_compact', 'max-h-16 max-w-16'))
                : @js(config('public_surfaces.theme.components.header.brand_link', 'max-h-24 max-w-24'))"
        >
            <x-ui.image
                :path="$brandLogo"
                :alt="$brandAlt ?: $brandName"
                :sizes="$brandSizes ?: config('public_surfaces.theme.brand.image_sizes', '(min-width:1024px) 40vw,100vw')"
                class="{{ config('public_surfaces.theme.components.header.brand_image', 'h-full w-full object-contain') }}"
                :placeholder="false"
            />
        </a>
    @elseif($brandLogoUrl)
        <a
            href="{{ $brandHref }}"
            aria-label="{{ $brandName }}"
            data-public-surface-brand
            class="transition-all"
            :class="compactLogo
                ? @js(config('public_surfaces.theme.components.header.brand_link_compact', 'max-h-16 max-w-16'))
                : @js(config('public_surfaces.theme.components.header.brand_link', 'max-h-24 max-w-24'))"
        >
            <img
                src="{{ $brandLogoUrl }}"
                alt="{{ $brandAlt ?: $brandName }}"
                class="{{ config('public_surfaces.theme.components.header.brand_image', 'h-full w-full object-contain') }}"
            >
        </a>
    @else
        <a
            href="{{ $brandHref }}"
            data-public-surface-brand
            class="{{ config('public_surfaces.theme.components.header.brand', 'text-lg font-extrabold tracking-tight text-white') }}"
        >
            {{ $brandName }}
        </a>
    @endif

    @if($surfaceLabel)
        <nav
            data-public-surface-nav
            class="{{ config('public_surfaces.theme.components.header.nav', 'hidden items-center gap-6 text-sm font-bold uppercase tracking-[0.12em] text-white/75 md:flex') }}"
        >
            <a
                href="{{ $surfaceHref ?: $brandHref }}"
                data-public-surface-label
                class="{{ config('public_surfaces.theme.components.header.nav_link', 'transition hover:text-[var(--public-primary)]') }}"
            >
                {{ $surfaceLabel }}
            </a>
        </nav>
    @endif
</div>