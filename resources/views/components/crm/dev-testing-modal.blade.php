@props([
    'name',
    'title' => 'Testing Tools',
    'subtitle' => null,
    'maxWidth' => 'max-w-7xl',
])

<div
    x-cloak
    x-show="activeDevTestingModal === @js($name)"
    x-on:keydown.escape.window="activeDevTestingModal = null"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-8"
    role="dialog"
    aria-modal="true"
>
    <div
        x-show="activeDevTestingModal === @js($name)"
        x-transition.opacity
        class="absolute inset-0"
        x-on:click="activeDevTestingModal = null"
    ></div>

    <div
        x-show="activeDevTestingModal === @js($name)"
        x-transition
        class="relative flex max-h-[90vh] w-full {{ $maxWidth }} flex-col overflow-hidden rounded-3xl bg-white shadow-2xl shadow-slate-950/30"
    >
        <div class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-indigo-600">
                    Dev / Staging Only
                </p>

                <h2 class="mt-1 text-lg font-bold text-slate-950">
                    {{ $title }}
                </h2>

                @if($subtitle)
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                        {{ $subtitle }}
                    </p>
                @endif
            </div>

            <button
                type="button"
                x-on:click="activeDevTestingModal = null"
                class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-bold text-slate-500 hover:bg-slate-50 hover:text-slate-900"
            >
                Close
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>
    </div>
</div>
