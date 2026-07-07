<x-layouts.app :title="$title ?? config('app.name')" :meta-description="$metaDescription ?? null">
    @php
        $moduleManager = app(\App\Support\Modules\ModuleManager::class);
        $navigationItems = $moduleManager->navigationItems();
        $navBaseClass = 'block rounded-lg px-3 py-2 font-medium text-slate-700 transition focus-visible:outline-none focus-visible:ring-2';
    @endphp

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <div class="flex min-h-screen">
            <aside class="hidden w-64 border-r border-slate-200 bg-white lg:flex lg:flex-col">
                <div class="border-b border-slate-200 px-6 py-5">
                    <div class="text-lg font-semibold tracking-tight">
                        {{ config('app.name') }}
                    </div>
                    <div class="mt-1 text-xs uppercase tracking-wide text-slate-500">
                        CRM
                    </div>
                </div>

                <nav class="flex-1 space-y-1 px-4 py-4 text-sm">
                    @foreach($navigationItems as $item)
                        <a
                            href="{{ $item['href'] }}"
                            class="{{ $navBaseClass }} {{ module_tone($item['module'], 'nav') }} {{ $item['class'] }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <form method="POST" action="/logout">
                        @csrf

                        <button
                            type="submit"
                            class="w-full rounded-lg px-3 py-2 text-left font-bold text-red-600 transition hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                        >
                            Logout
                        </button>
                    </form>
                </nav>
            </aside>

            <div class="flex min-h-screen flex-1 flex-col">
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4">
                        <div>
                            <h1 class="text-lg font-semibold tracking-tight capitalize">
                                {{ $heading ?? ($title ?? 'CRM') }}
                            </h1>

                            @if(!empty($subheading ?? null))
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $subheading }}
                                </p>
                            @endif
                        </div>
                    </div>
                </header>

                <main class="flex-1">
                    <div class="mx-auto w-full max-w-375 px-6 py-8">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </div>
</x-layouts.app>
