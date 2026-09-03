@props([
    'title' => null,
    'metaDescription' => null,
    'robots' => null,
    'bodyClass' => 'min-h-screen bg-slate-50 text-slate-950',
    'headerClass' => 'border-b border-slate-200/80 bg-white/95 backdrop-blur',
    'mainClass' => 'flex-1',
    'footerClass' => 'border-t border-slate-200 bg-white',
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
        class="flex min-h-screen flex-col {{ $bodyClass }}"
        style="--public-primary: {{ $primaryColor ?: 'var(--brand-primary)' }}; --public-accent: {{ $accentColor ?: 'var(--brand-primary-light)' }}; --public-surface: {{ $surfaceColor ?: '#ffffff' }}; --public-background: {{ $backgroundColor ?: '#f8fafc' }};"
    >
        @isset($header)
            <header class="{{ $headerClass }}">
                {{ $header }}
            </header>
        @endisset

        <main class="{{ $mainClass }}">
            {{ $slot }}
        </main>

        @isset($footer)
            <footer class="{{ $footerClass }}">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</x-layouts.app>