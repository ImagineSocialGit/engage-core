<x-layouts.app :title="$title ?? config('app.name')" :meta-description="$metaDescription ?? null">
    <div class="min-h-screen bg-white text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4">
                <a href="/" class="text-lg font-semibold tracking-tight">
                    {{ config('app.name') }}
                </a>

                <nav class="hidden items-center gap-6 text-sm font-medium md:flex">
                    <a href="/" class="transition hover:opacity-70">Webinar</a>
                </nav>
            </div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-7xl px-6 py-6 text-sm text-slate-500">
                {{ config('app.name') }}
            </div>
        </footer>
    </div>
</x-layouts.app>