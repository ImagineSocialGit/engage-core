<div
    x-data="{
        searchUrl: @js(route('crm.contacts.lookup')),
        query: '',
        searching: false,
        results: [],
        selected: null,
        async searchContacts() {
            const value = this.query.trim();

            if (value.length < 2) {
                this.results = [];
                return;
            }

            this.searching = true;

            try {
                const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(value)}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                this.results = data.contacts || [];
            } finally {
                this.searching = false;
            }
        },
        choose(contact) {
            this.selected = contact;
            this.query = '';
            this.results = [];
        },
        clear() {
            this.selected = null;
            this.query = '';
            this.results = [];
        },
    }"
    class="space-y-3"
>
    <input type="hidden" name="contact_id" x-bind:value="selected?.id || ''">

    <div x-show="selected" x-cloak class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-slate-900" x-text="selected?.name || selected?.email || selected?.phone || `Contact #${selected?.id}`"></div>
            <div class="truncate text-xs text-slate-500" x-text="[selected?.email, selected?.phone].filter(Boolean).join(' · ')"></div>
        </div>
        <button type="button" x-on:click="clear" class="shrink-0 text-xs font-semibold text-slate-600 underline">
            Change
        </button>
    </div>

    <div x-show="! selected" class="relative">
        <label for="event-attendance-contact-search" class="text-sm font-semibold text-slate-800">Contact</label>
        <input
            id="event-attendance-contact-search"
            type="search"
            x-model="query"
            x-on:input.debounce.250ms="searchContacts"
            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
            placeholder="Search by name, email, or phone"
            autocomplete="off"
        >

        <div x-show="query.trim().length >= 2" x-cloak class="absolute z-20 mt-2 w-full rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
            <p x-show="searching" class="px-2 py-2 text-sm text-slate-500">Searching…</p>
            <p x-show="! searching && results.length === 0" class="px-2 py-2 text-sm text-slate-500">No matching visible Contacts.</p>

            <div x-show="! searching" class="max-h-64 divide-y divide-slate-100 overflow-y-auto">
                <template x-for="contact in results" :key="contact.id">
                    <button type="button" x-on:click="choose(contact)" class="block w-full px-2 py-2 text-left hover:bg-slate-50">
                        <span class="block text-sm font-semibold text-slate-900" x-text="contact.name || contact.email || contact.phone || `Contact #${contact.id}`"></span>
                        <span class="block text-xs text-slate-500" x-text="[contact.email, contact.phone].filter(Boolean).join(' · ')"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <x-ui.form.error name="contact_id" />
</div>