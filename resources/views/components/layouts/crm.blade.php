@props([
    'title' => null,
    'heading' => null,
    'subheading' => null,
    'metaDescription' => null,
    'module' => null,
])

<x-layouts.app :title="$title ?? config('app.name')" :meta-description="$metaDescription">
    @php
        $moduleManager = app(\App\Support\Modules\ModuleManager::class);
        $navigationItems = $moduleManager->navigationItems();
        $navBaseClass = 'block rounded-lg px-3 py-2 font-medium text-slate-700 transition focus-visible:outline-none focus-visible:ring-2';
        $mainSurfaceClass = $module ? module_tone($module, 'panel') : 'bg-slate-50';
    @endphp

    <div
        x-data="{ mobileNavOpen: false }"
        x-on:keydown.escape.window="mobileNavOpen = false"
        class="min-h-screen bg-slate-50 text-slate-900"
    >
        <div class="flex min-h-screen min-w-0">
            <aside class="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:flex lg:flex-col">
                <div class="border-b border-slate-200 px-6 py-5">
                    <div class="text-lg font-semibold tracking-tight">
                        {{ config('app.name') }}
                    </div>
                    <div class="mt-1 text-xs uppercase tracking-wide text-slate-500">
                        CRM
                    </div>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto px-4 py-4 text-sm" aria-label="CRM navigation">
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

            <div
                x-cloak
                x-show="mobileNavOpen"
                x-transition.opacity
                class="fixed inset-0 z-50 lg:hidden"
            >
                <button
                    type="button"
                    class="absolute inset-0 bg-slate-950/40"
                    aria-label="Close CRM navigation"
                    x-on:click="mobileNavOpen = false; $nextTick(() => $refs.mobileNavButton.focus())"
                ></button>

                <aside
                    id="crm-mobile-navigation"
                    class="relative flex h-full w-80 max-w-[calc(100vw-3rem)] flex-col border-r border-slate-200 bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-label="CRM navigation"
                >
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <div class="font-semibold tracking-tight">
                                {{ config('app.name') }}
                            </div>
                            <div class="mt-0.5 text-xs uppercase tracking-wide text-slate-500">
                                CRM
                            </div>
                        </div>

                        <button
                            x-ref="mobileNavClose"
                            type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            aria-label="Close CRM navigation"
                            x-on:click="mobileNavOpen = false; $nextTick(() => $refs.mobileNavButton.focus())"
                        >
                            <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>

                    <nav class="flex-1 space-y-1 overflow-y-auto px-4 py-4 text-sm" aria-label="Mobile CRM navigation">
                        @foreach($navigationItems as $item)
                            <a
                                href="{{ $item['href'] }}"
                                class="{{ $navBaseClass }} {{ module_tone($item['module'], 'nav') }} {{ $item['class'] }}"
                                x-on:click="mobileNavOpen = false"
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
            </div>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto flex w-full max-w-375 items-start gap-3 px-4 py-3 sm:px-6 sm:py-4">
                        <button
                            x-ref="mobileNavButton"
                            type="button"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 lg:hidden"
                            aria-label="Open CRM navigation"
                            aria-controls="crm-mobile-navigation"
                            x-bind:aria-expanded="mobileNavOpen.toString()"
                            x-on:click="mobileNavOpen = true; $nextTick(() => $refs.mobileNavClose.focus())"
                        >
                            <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                            </svg>
                        </button>

                        <div class="min-w-0 flex-1 pt-0.5">
                            <h1 class="text-lg font-semibold tracking-tight capitalize text-slate-950">
                                {{ $heading ?? ($title ?? 'CRM') }}
                            </h1>

                            @if(!empty($subheading))
                                <p class="mt-1 text-sm leading-5 text-slate-600">
                                    {{ $subheading }}
                                </p>
                            @endif
                        </div>
                    </div>
                </header>

                <main class="min-w-0 flex-1 {{ $mainSurfaceClass }}">
                    <div class="mx-auto w-full max-w-375 px-4 py-6 sm:px-6 sm:py-8">
                        @if(session()->has(\App\Support\Guidance\FirstUseGuidance::SESSION_KEY))
                            <div class="mb-6">
                                <x-ui.feedback.contextual-guidance
                                    :guidance="session(\App\Support\Guidance\FirstUseGuidance::SESSION_KEY)"
                                />
                            </div>
                        @endif

                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </div>
</x-layouts.app>