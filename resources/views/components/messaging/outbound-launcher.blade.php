@props(['url', 'label' => 'Upcoming messages'])
<div x-data="{ open: false, loaded: false }" x-on:keydown.escape.window="open = false">
    <button type="button" x-on:click="loaded = true; open = true; $nextTick(() => $refs.close.focus())"
        {{ $attributes->class(['inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50']) }}>
        {{ $label }}
    </button>
    <template x-if="loaded">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-5"
            role="dialog" aria-modal="true" aria-label="Outbound messages">
            <div class="absolute inset-0 bg-slate-950/60" x-on:click="open = false"></div>
            <div class="relative flex h-[90vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <span class="font-semibold text-slate-900">Outbound messages</span>
                    <div class="flex gap-3">
                        <a href="{{ str_replace(['&embedded=1', '?embedded=1'], '', $url) }}" target="_blank" rel="noopener" class="text-sm font-medium text-slate-600 underline">Open full page</a>
                        <button x-ref="close" type="button" x-on:click="open = false" class="text-sm font-semibold text-slate-700">Close</button>
                    </div>
                </div>
                <iframe title="Outbound messages" src="{{ $url }}" class="min-h-0 w-full flex-1 border-0"></iframe>
            </div>
        </div>
    </template>
</div>