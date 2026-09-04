@props([
    'title' => null,
    'metaDescription' => null,
])

<x-layouts.public-surface
    :title="$title ?? data_get(config('webinars.content', []), 'brand.name', config('app.name'))"
    :meta-description="$metaDescription"
    :body-class="data_get(config('webinars.style', []), 'layout.body', config('public_surfaces.theme.layout.body', 'bg-white text-slate-900 font-sans'))"
    :main-class="data_get(config('webinars.style', []), 'layout.main', config('public_surfaces.theme.layout.main', 'flex-1'))"
    :footer-class="data_get(config('webinars.style', []), 'layout.footer.wrap', config('public_surfaces.theme.layout.footer', 'border-t border-slate-200 bg-white'))"
>
    <x-slot:header>
        <x-public-surface.header
            :brand-name="data_get(config('webinars.content', []), 'brand.name', config('app.name'))"
            :brand-logo="data_get(config('webinars.content', []), 'brand.logo') ?: config('public_surfaces.theme.brand.logo')"
            :brand-alt="data_get(config('webinars.content', []), 'brand.image_alt', data_get(config('webinars.content', []), 'brand.logo_alt', config('app.name')))"
            :brand-sizes="data_get(config('webinars.content', []), 'brand.image_sizes', config('public_surfaces.theme.brand.image_sizes'))"
            :brand-href="route('webinar.index')"
            surface-label="Webinars"
            :surface-href="route('webinar.index')"
        />
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