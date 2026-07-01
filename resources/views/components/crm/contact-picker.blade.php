@props([
    'selectedContacts' => collect(),
    'inputName' => 'contact_ids[]',
])

@php
    $selectedContactsForPicker = $selectedContacts
        ->map(fn ($contact) => [
            'id' => $contact->id,
            'label' => ($contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email ?: 'Contact #'.$contact->id)
                .($contact->email ? ' — '.$contact->email : ''),
            'name' => $contact->name ?: trim($contact->first_name.' '.$contact->last_name),
            'email' => $contact->email,
            'phone' => $contact->phone,
        ])
        ->values();
@endphp

<div
    x-data="{
        searchUrl: @js(route('crm.contacts.lookup')),
        query: '',
        open: false,
        searching: false,
        results: [],
        selectedContacts: @js($selectedContactsForPicker),
        async searchContacts() {
            const value = this.query.trim();

            if (value.length < 2) {
                this.results = [];
                return;
            }

            this.searching = true;

            try {
                const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(value)}`, {
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();

                this.results = (data.contacts || []).filter((contact) => {
                    return ! this.selectedContacts.some((selected) => Number(selected.id) === Number(contact.id));
                });
            } finally {
                this.searching = false;
            }
        },
        addContact(contact) {
            if (! this.selectedContacts.some((selected) => Number(selected.id) === Number(contact.id))) {
                this.selectedContacts.push(contact);
            }

            this.query = '';
            this.results = [];
            this.open = false;
        },
        removeContact(contactId) {
            this.selectedContacts = this.selectedContacts.filter((contact) => Number(contact.id) !== Number(contactId));
        },
    }"
    class="space-y-3"
>
    <template x-for="contact in selectedContacts" :key="`hidden-${contact.id}`">
        <input type="hidden" name="{{ $inputName }}" :value="contact.id">
    </template>

    <div class="flex flex-wrap gap-2">
        <template x-for="contact in selectedContacts" :key="contact.id">
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm text-slate-700">
                <span x-text="contact.label"></span>

                <button
                    type="button"
                    class="text-slate-400 hover:text-red-600"
                    @click="removeContact(contact.id)"
                    aria-label="Remove contact"
                >
                    &times;
                </button>
            </span>
        </template>

        <template x-if="selectedContacts.length === 0">
            <p class="text-sm text-slate-500">
                No individual contacts selected.
            </p>
        </template>
    </div>

    <div class="relative">
        <button
            type="button"
            class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="open = ! open; if (open) { $nextTick(() => $refs.search.focus()) }"
        >
            Add Individual Contacts
        </button>

        <div
            x-cloak
            x-show="open"
            @click.outside="open = false"
            class="absolute z-20 mt-2 w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-4 shadow-xl"
        >
            <label class="block text-sm font-medium text-slate-700" for="broadcast-contact-picker-search">
                Search contacts
            </label>

            <input
                id="broadcast-contact-picker-search"
                x-ref="search"
                x-model="query"
                @input.debounce.250ms="searchContacts"
                type="search"
                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                placeholder="Search by name, email, or phone"
            >

            <div class="mt-3 max-h-64 overflow-y-auto">
                <template x-if="searching">
                    <p class="px-2 py-3 text-sm text-slate-500">
                        Searching...
                    </p>
                </template>

                <template x-if="! searching && query.trim().length > 0 && query.trim().length < 2">
                    <p class="px-2 py-3 text-sm text-slate-500">
                        Type at least 2 characters.
                    </p>
                </template>

                <template x-if="! searching && query.trim().length >= 2 && results.length === 0">
                    <p class="px-2 py-3 text-sm text-slate-500">
                        No matching contacts.
                    </p>
                </template>

                <div class="divide-y divide-slate-100">
                    <template x-for="contact in results" :key="contact.id">
                        <button
                            type="button"
                            class="block w-full px-2 py-3 text-left hover:bg-slate-50"
                            @click="addContact(contact)"
                        >
                            <span
                                class="block text-sm font-medium text-slate-900"
                                x-text="contact.name || contact.email || contact.phone || `Contact #${contact.id}`"
                            ></span>

                            <span
                                class="block text-xs text-slate-500"
                                x-text="[contact.email, contact.phone].filter(Boolean).join(' · ')"
                            ></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <p class="text-xs text-slate-500">
        Used only when Recipients is set to Selected contacts.
    </p>

    <x-ui.form.error name="contact_ids" />
    <x-ui.form.error name="contact_ids.*" />
</div>