@props([
    'presentation',
    'navigation' => [],
    'portalUser' => null,
])

<x-layouts.public-surface
    :title="$presentation['title']"
    :robots="$presentation['robots']"
    :primary-color="$presentation['primary_color']"
    :accent-color="$presentation['accent_color']"
    :surface-color="$presentation['surface_color']"
    :background-color="$presentation['background_color']"
>
    <x-slot:header>
        <x-public-surface.header
            :brand-name="$presentation['brand_name']"
            :brand-logo="$presentation['logo']"
            :brand-logo-url="$presentation['logo_url']"
            :brand-alt="$presentation['brand_name']"
            :brand-href="route('portal.home')"
            :surface-label="$presentation['surface_label']"
            :surface-href="route('portal.home')"
        />
    </x-slot:header>

    <x-slot:footer>
        <div class="mx-auto w-full max-w-7xl px-6 py-6 text-sm text-slate-500">
            {{ $presentation['brand_name'] }}
        </div>
    </x-slot:footer>

    <main class="mx-auto w-full max-w-6xl px-6 py-8 sm:py-10">
        @if($portalUser)
            <div class="mb-8 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <nav class="flex flex-wrap gap-2" aria-label="Portal navigation">
                    @foreach($navigation as $item)
                        <a
                            href="{{ route($item->routeName, $item->routeParameters) }}"
                            class="rounded-full px-4 py-2 text-sm font-semibold {{ request()->routeIs($item->routeName) ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950' }}"
                        >
                            {{ $item->label }}
                        </a>
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-full px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-950">
                        Sign out
                    </button>
                </form>
            </div>
        @endif

        {{ $slot }}
    </main>
</x-layouts.public-surface>