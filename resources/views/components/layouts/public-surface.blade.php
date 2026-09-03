@props([
    'title' => null,
    'metaDescription' => null,
    'robots' => null,
    'bodyClass' => null,
    'headerClass' => null,
    'mainClass' => null,
    'footerClass' => null,
    'primaryColor' => null,
    'accentColor' => null,
    'surfaceColor' => null,
    'backgroundColor' => null,
])

<x-layouts.app
    :title="$title"
    :meta-description="$metaDescription"
    :robots="$robots"
>
    <div
        data-public-surface
        class="flex min-h-screen flex-col {{ $bodyClass ?: config('public_surfaces.theme.layout.body', 'bg-slate-50 text-slate-950 font-sans') }}"
        style="--public-primary: {{ $primaryColor ?: config('public_surfaces.theme.colors.primary') ?: 'var(--brand-primary)' }}; --public-accent: {{ $accentColor ?: config('public_surfaces.theme.colors.accent') ?: 'var(--brand-primary-light)' }}; --public-surface: {{ $surfaceColor ?: config('public_surfaces.theme.colors.surface', '#ffffff') }}; --public-background: {{ $backgroundColor ?: config('public_surfaces.theme.colors.background', '#f8fafc') }};"
    >
        @isset($header)
            <header class="{{ $headerClass ?: config('public_surfaces.theme.layout.header', 'border-b border-slate-200/80 bg-white/95 backdrop-blur') }}">
                {{ $header }}
            </header>
        @endisset

        <main class="{{ $mainClass ?: config('public_surfaces.theme.layout.main', 'flex-1') }}">
            {{ $slot }}
        </main>

        @isset($footer)
            <footer class="{{ $footerClass ?: config('public_surfaces.theme.layout.footer', 'border-t border-slate-200 bg-white') }}">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</x-layouts.app>