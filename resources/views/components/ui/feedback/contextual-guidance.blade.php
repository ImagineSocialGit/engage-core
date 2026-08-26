@props(['guidance'])

@if(is_array($guidance))
    <aside
        role="status"
        class="rounded-2xl border border-sky-200 bg-sky-50 p-4 shadow-sm sm:flex sm:items-start sm:justify-between sm:gap-5"
    >
        <div class="min-w-0">
            <p class="font-semibold text-slate-950">
                {{ $guidance['title'] ?? '' }}
            </p>

            <p class="mt-1 text-sm leading-6 text-slate-700">
                {{ $guidance['message'] ?? '' }}
            </p>
        </div>

        @if(filled($guidance['action_url'] ?? null) && filled($guidance['action_label'] ?? null))
            <a
                href="{{ $guidance['action_url'] }}"
                class="mt-3 inline-flex min-h-10 w-full shrink-0 items-center justify-center rounded-xl border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-sky-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 sm:mt-0 sm:w-auto"
            >
                {{ $guidance['action_label'] }}
            </a>
        @endif
    </aside>
@endif