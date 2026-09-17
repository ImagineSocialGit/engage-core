<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
    <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    <form method="POST" action="{{ route('crm.messaging.outbound.contact-group') }}"
        target="outbound-contact-result-frame" class="mt-3" x-on:submit="open = true; $nextTick(() => $refs.close.focus())">
        @csrf
        <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />
        <x-ui.button type="submit" variant="secondary" class="w-full">Show scheduled messages</x-ui.button>
    </form>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-5"
        role="dialog" aria-modal="true" aria-label="Messages for contact group">
        <div class="absolute inset-0 bg-slate-950/60" x-on:click="open = false"></div>
        <div class="relative flex h-[90vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
            <div class="flex justify-between border-b border-slate-200 px-4 py-3">
                <span class="font-semibold text-slate-900">Messages for current contact group</span>
                <button x-ref="close" type="button" x-on:click="open = false" class="text-sm font-semibold text-slate-700">Close</button>
            </div>
            <iframe title="Messages for current contact group" name="outbound-contact-result-frame" class="min-h-0 w-full flex-1 border-0"></iframe>
        </div>
    </div>
</div>