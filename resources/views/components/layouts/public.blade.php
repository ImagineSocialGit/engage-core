@php
    $theme = config('theme.webinar_public', []);
    $brand = $theme['brand'] ?? [];
    $layout = $theme['layout'] ?? [];
    $header = $layout['header'] ?? [];
    $footer = $layout['footer'] ?? [];
    $primaryLink = $header['primary_link'] ?? [];

    $brandName = $brand['name'] ?? config('app.name');
    $primaryLinkLabel = $primaryLink['label'] ?? 'Webinars';
    $primaryLinkHref = isset($primaryLink['route']) ? route($primaryLink['route']) : '/';
@endphp

<x-layouts.app :title="$title ?? $brandName" :meta-description="$metaDescription ?? null">
    <div class="{{ $layout['body'] ?? 'min-h-screen flex flex-col bg-white text-slate-900' }}">
        <header class="{{ $header['wrap'] ?? 'border-b border-slate-200 bg-white' }}">
            <div class="{{ $header['inner'] ?? 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4' }}">
                <a href="{{ $primaryLinkHref }}" class="{{ $header['brand'] ?? 'text-lg font-semibold tracking-tight' }}">
                    {{ $brandName }}
                </a>

                <nav class="{{ $header['nav'] ?? 'hidden items-center gap-6 text-sm font-medium md:flex' }}">
                    <a href="{{ $primaryLinkHref }}" class="{{ $header['nav_link'] ?? 'transition hover:opacity-70' }}">
                        {{ $primaryLinkLabel }}
                    </a>
                </nav>
            </div>
        </header>

        <main class="{{ $layout['main'] ?? 'flex-1' }}">
            {{ $slot }}
        </main>

        <footer class="{{ $footer['wrap'] ?? 'border-t border-slate-200 bg-white' }}">
            <div class="{{ $footer['inner'] ?? 'mx-auto w-full max-w-7xl px-6 py-6 text-sm text-slate-500' }}">
                {{ $footer['text'] ?? $brandName }}
            </div>
        </footer>
    </div>
</x-layouts.app>