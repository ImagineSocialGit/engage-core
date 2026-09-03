@props([
    'title' => null,
    'metaDescription' => null,
])

<x-layouts.public-surface
    :title="$title ?? data_get(config('webinars.content', []), 'brand.name', config('app.name'))"
    :meta-description="$metaDescription"
    :body-class="data_get(config('webinars.style', []), 'layout.body', 'min-h-screen flex flex-col bg-white text-slate-900')"
    :header-class="data_get(config('webinars.style', []), 'layout.header.wrap', 'border-b border-slate-200 bg-white')"
    :main-class="data_get(config('webinars.style', []), 'layout.main', 'flex-1')"
    :footer-class="data_get(config('webinars.style', []), 'layout.footer.wrap', 'border-t border-slate-200 bg-white')"
>
    <x-slot:header>
        <div
            x-data="{compactLogo: false}"
            x-init="window.addEventListener('scroll', () => {
                compactLogo = window.scrollY > 60;
            }, { passive: true });"
            class="{{ data_get(config('webinars.style', []), 'layout.header.inner', 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4') }}"
        >
            @if(data_get(config('webinars.content', []), 'brand.logo'))
                <a
                    href="{{ data_get(config('webinars.style', []), 'layout.header.primary_link.route') ? route(data_get(config('webinars.style', []), 'layout.header.primary_link.route')) : url('/') }}"
                    class="transition-all"
                    :class="compactLogo ? '{{ data_get(config('webinars.style', []), 'layout.header.brand_link_compact', 'max-w-16 max-h-16') }}' : '{{ data_get(config('webinars.style', []), 'layout.header.brand_link', 'max-w-20 max-h-20') }}'"
                >
                    <x-ui.image
                        :path="data_get(config('webinars.content', []), 'brand.logo')"
                        :alt="data_get(config('webinars.content', []), 'brand.image_alt', 'Logo')"
                        :sizes="data_get(config('webinars.content', []), 'brand.image_sizes', '(min-width:1024px) 40vw,100vw')"
                        class="{{ data_get(config('webinars.style', []), 'layout.header.brand_image', 'w-full rounded-3xl object-cover') }}"
                        :placeholder="false"
                    />
                </a>
            @else
                <a
                    href="{{ data_get(config('webinars.style', []), 'layout.header.primary_link.route') ? route(data_get(config('webinars.style', []), 'layout.header.primary_link.route')) : url('/') }}"
                    class="{{ data_get(config('webinars.style', []), 'layout.header.brand', 'text-lg font-semibold tracking-tight') }}"
                >
                    {{ data_get(config('webinars.content', []), 'brand.name', config('app.name')) }}
                </a>
            @endif

            <nav class="{{ data_get(config('webinars.style', []), 'layout.header.nav', 'hidden items-center gap-6 text-sm font-medium md:flex') }}">
                <a
                    href="{{ data_get(config('webinars.style', []), 'layout.header.primary_link.route') ? route(data_get(config('webinars.style', []), 'layout.header.primary_link.route')) : url('/') }}"
                    class="{{ data_get(config('webinars.style', []), 'layout.header.nav_link', 'transition hover:opacity-70') }}"
                >
                    {{ data_get(config('webinars.style', []), 'layout.header.primary_link.label', 'Webinars') }}
                </a>
            </nav>
        </div>
    </x-slot:header>

    <x-slot:footer>
        @if(data_get(config('webinars.content', []), 'footer.compliance_identity.enabled', false))
            <div class="{{ data_get(config('webinars.style', []), 'footer.compliance_identity.wrapper', 'mt-6 text-center') }}">
                @foreach(data_get(config('webinars.content', []), 'footer.compliance_identity.lines', []) as $line)
                    <span class="{{ data_get(config('webinars.style', []), 'footer.compliance_identity.line', 'block text-xs leading-6 text-white/90') }}">
                        {{ $line }}
                    </span>
                @endforeach
            </div>
        @endif
        <div class="{{ data_get(config('webinars.style', []), 'layout.footer.inner', 'mx-auto w-full max-w-7xl px-6 py-6 text-sm text-slate-500') }}">
            {{ data_get(config('webinars.style', []), 'layout.footer.text', data_get(config('webinars.content', []), 'brand.name', config('app.name'))) }}
        </div>
    </x-slot:footer>

    {{ $slot }}
</x-layouts.public-surface>