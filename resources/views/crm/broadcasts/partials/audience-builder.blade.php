<section
    class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
    x-data="{
        recipientFilterType: @js($audienceFormState['filter_type'] ?? 'criteria'),
        preview: null,
        previewLoading: false,
        previewError: null,
        previewContactsOpen: false,
        manualExcludedContactIds: @js($audienceFormState['exclude_contact_ids'] ?? []),
        async previewAudience(openContacts = false) {
            this.previewLoading = true;
            this.previewError = null;

            try {
                const form = this.$root.closest('form');
                const body = new FormData(form);
                const response = await fetch(@js(route('crm.broadcasts.audience-preview')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                });

                const payload = await response.json();

                if (! response.ok) {
                    const errors = payload.errors ?? {};
                    const first = Object.values(errors).flat()[0] ?? payload.message ?? 'Audience preview failed.';
                    throw new Error(first);
                }

                this.preview = payload;
                this.previewContactsOpen = openContacts && payload.selected_count > 0;
            } catch (error) {
                this.preview = null;
                this.previewContactsOpen = false;
                this.previewError = error?.message ?? 'Audience preview failed.';
            } finally {
                this.previewLoading = false;
            }
        },
        async removePreviewContact(contactId) {
            const id = Number(contactId);

            if (! this.manualExcludedContactIds.includes(id)) {
                this.manualExcludedContactIds.push(id);
            }

            await this.$nextTick();
            await this.previewAudience(true);
        },
        async clearManualRemovals() {
            this.manualExcludedContactIds = [];
            await this.$nextTick();
            await this.previewAudience(this.previewContactsOpen);
        },
        clearAudienceFilters() {
            this.recipientFilterType = 'criteria';
            this.manualExcludedContactIds = [];
            this.preview = null;
            this.previewError = null;
            this.previewContactsOpen = false;

            this.$root.querySelectorAll('[data-audience-filter-select], [data-audience-exclusion-select], [data-prior-broadcast-exclusion]').forEach((select) => {
                [...select.options].forEach((option) => option.selected = false);
            });

            this.$root.querySelectorAll('[data-prior-broadcast-status]').forEach((checkbox) => {
                checkbox.checked = false;
            });
        },
    }"
    x-on:keydown.escape.window="previewContactsOpen = false"
>
    <template x-for="contactId in manualExcludedContactIds" :key="`manual-exclude-${contactId}`">
        <input type="hidden" name="exclude_contact_ids[]" x-bind:value="contactId">
    </template>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">1. Who</p>
            <h3 class="mt-1 text-base font-semibold text-slate-900">Choose the audience first</h3>
            <p class="mt-1 text-sm text-slate-600">
                Include criteria are combined with AND. Multiple choices inside one criterion are OR. Exclusions are subtracted afterward.
            </p>
        </div>

        <button
            type="button"
            class="shrink-0 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
            x-on:click="clearAudienceFilters()"
        >
            Clear filters
        </button>
    </div>

    <div>
        <x-ui.form.label for="recipient_filter_type">Audience type</x-ui.form.label>
        <x-ui.form.select
            id="recipient_filter_type"
            name="recipient_filter_type"
            x-model="recipientFilterType"
        >
            <option value="criteria">Match criteria</option>
            <option value="contact_ids">Selected contacts</option>
            <option value="all">All contacts</option>
            @if(($audienceFormState['filter_type'] ?? null) === 'tag')
                <option value="tag">Legacy tag filter</option>
            @endif
        </x-ui.form.select>
        <x-ui.form.error name="recipient_filter_type" />
    </div>

    <div x-show="recipientFilterType === 'criteria'" x-cloak class="grid gap-4 lg:grid-cols-2">
        @foreach($audienceCriteria as $criterion)
            @if(($criterion['options'] ?? []) !== [])
                <div>
                    <x-ui.form.label for="recipient_criteria_{{ $criterion['key'] }}">
                        {{ $criterion['label'] }}
                    </x-ui.form.label>

                    <select
                        id="recipient_criteria_{{ $criterion['key'] }}"
                        name="recipient_criteria[{{ $criterion['key'] }}][]"
                        multiple
                        data-audience-filter-select
                        class="mt-1 block min-h-28 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                    >
                        @foreach($criterion['options'] as $option)
                            <option
                                value="{{ $option['value'] }}"
                                @selected($option['included'] ?? false)
                            >
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>

                    @if(filled($criterion['help'] ?? null))
                        <p class="mt-1 text-xs text-slate-500">{{ $criterion['help'] }}</p>
                    @endif
                </div>
            @endif
        @endforeach
    </div>

    <div x-show="recipientFilterType === 'contact_ids'" x-cloak>
        <x-ui.form.label>Selected contacts</x-ui.form.label>
        <div class="mt-2">
            <x-crm.contact-picker
                :selected-contacts="$selectedRecipientContacts"
                input-name="contact_ids[]"
            />
        </div>
    </div>

    @if(($audienceFormState['filter_type'] ?? null) === 'tag')
        <div x-show="recipientFilterType === 'tag'" x-cloak>
            <x-ui.form.label for="recipient_tag">Contact tag</x-ui.form.label>
            <x-ui.form.input
                id="recipient_tag"
                name="recipient_tag"
                value="{{ $audienceFormState['tag'] ?? '' }}"
            />
        </div>
    @endif

    <details class="rounded-xl border border-slate-200 bg-white p-3" @if($audienceFormState['has_contact_exclusions'] ?? false) open @endif>
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Exclude contacts</summary>
        <p class="mt-2 text-xs text-slate-500">
            Subtract contacts by the same filter facts used to build the audience. You can also remove individual people after previewing the list.
        </p>

        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            @foreach($audienceCriteria as $criterion)
                @if(($criterion['options'] ?? []) !== [])
                    <div>
                        <x-ui.form.label for="exclude_contact_criteria_{{ $criterion['key'] }}">
                            Exclude by {{ str($criterion['label'])->lower() }}
                        </x-ui.form.label>

                        <select
                            id="exclude_contact_criteria_{{ $criterion['key'] }}"
                            name="exclude_contact_criteria[{{ $criterion['key'] }}][]"
                            multiple
                            data-audience-exclusion-select
                            class="mt-1 block min-h-24 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            @foreach($criterion['options'] as $option)
                                <option
                                    value="{{ $option['value'] }}"
                                    @selected($option['excluded'] ?? false)
                                >
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3" x-show="manualExcludedContactIds.length > 0" x-cloak>
            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">
                <span x-text="manualExcludedContactIds.length"></span> manually removed
            </span>
            <button type="button" class="text-xs font-semibold text-slate-600 underline" x-on:click="clearManualRemovals()">
                Restore manually removed contacts
            </button>
        </div>
    </details>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button
            type="button"
            variant="secondary"
            x-on:click="previewAudience(false)"
            x-bind:disabled="previewLoading"
        >
            <span x-show="! previewLoading">Preview audience</span>
            <span x-show="previewLoading" x-cloak>Checking…</span>
        </x-ui.button>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            x-on:click="previewAudience(true)"
            x-bind:disabled="previewLoading"
        >
            View contacts
        </button>

        <p class="text-xs text-slate-500">
            Preview before composing so you can inspect who is included, remove individual contacts, and review prior Broadcast overlap.
        </p>
    </div>

    <template x-if="previewError">
        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" x-text="previewError"></div>
    </template>

    <template x-if="preview">
        <div class="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Matches</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900" x-text="preview.selected_count"></div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">No consent on record</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900" x-text="preview.without_any_consent_count"></div>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-slate-900">Previous Broadcasts to people in this audience</h4>
                <p class="mt-1 text-xs text-slate-500">
                    This is audience overlap, not a claim that every matching person received every message successfully.
                </p>

                <template x-if="preview.previous_broadcasts.length === 0">
                    <p class="mt-3 text-sm text-slate-500">No prior scheduled/sent Broadcast overlap found.</p>
                </template>

                <div class="mt-3 space-y-2" x-show="preview.previous_broadcasts.length > 0">
                    <template x-for="item in preview.previous_broadcasts" :key="item.id">
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <div>
                                <div class="font-semibold text-slate-900" x-text="item.name"></div>
                                <div class="text-xs text-slate-500">
                                    <span x-text="item.channel.toUpperCase()"></span>
                                    · <span x-text="item.sent_count"></span> sent
                                    · <span x-text="item.scheduled_count"></span> still scheduled
                                </div>
                            </div>
                            <div class="font-semibold text-slate-700">
                                <span x-text="item.overlap_count"></span> overlap
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div x-show="preview.without_any_consent_count > 0" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Some matching contacts have no consent recorded. If imported contacts are eligible for the one-time permission-request flow, that option appears separately on the Broadcasts page.
            </div>
        </div>
    </template>

    @if($excludableBroadcasts->isNotEmpty())
        <details class="rounded-xl border border-slate-200 bg-white p-3">
            <summary class="cursor-pointer text-sm font-semibold text-slate-800">Avoid duplicate sends</summary>
            <p class="mt-2 text-xs text-slate-500">
                Optionally exclude contacts who were already scheduled or sent selected previous Broadcasts.
            </p>

            <div class="mt-3">
                <x-ui.form.label for="exclude_broadcast_ids">Previous Broadcasts to exclude</x-ui.form.label>
                <select
                    id="exclude_broadcast_ids"
                    name="exclude_broadcast_ids[]"
                    multiple
                    data-prior-broadcast-exclusion
                    class="mt-1 block min-h-28 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                >
                    @foreach($excludableBroadcasts as $excludableBroadcast)
                        <option
                            value="{{ $excludableBroadcast->id }}"
                            @selected(in_array((int) $excludableBroadcast->id, $audienceFormState['exclude_broadcast_ids'] ?? [], true))
                        >
                            {{ $excludableBroadcast->name }}
                            — {{ strtoupper($excludableBroadcast->channel) }}
                            — {{ str_replace('_', ' ', $excludableBroadcast->status) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mt-3 flex flex-wrap gap-4 text-sm text-slate-700">
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="exclude_broadcast_statuses[]"
                        value="scheduled"
                        data-prior-broadcast-status
                        @checked($audienceFormState['exclude_scheduled'] ?? false)
                        class="rounded border-slate-300"
                    >
                    Scheduled
                </label>

                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="exclude_broadcast_statuses[]"
                        value="sent"
                        data-prior-broadcast-status
                        @checked($audienceFormState['exclude_sent'] ?? false)
                        class="rounded border-slate-300"
                    >
                    Sent
                </label>
            </div>
        </details>
    @endif

    <template x-teleport="body">
        <div
            x-cloak
            x-show="previewContactsOpen"
            x-transition.opacity
            x-on:click.self="previewContactsOpen = false"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4"
            role="presentation"
        >
            <section
                x-show="previewContactsOpen"
                x-transition
                class="max-h-[90dvh] w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-black/10"
                role="dialog"
                aria-modal="true"
                aria-labelledby="broadcast-audience-preview-heading"
            >
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Broadcast audience</p>
                        <h2 id="broadcast-audience-preview-heading" class="mt-1 text-xl font-semibold text-slate-950">
                            Contacts in this Broadcast
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            <span x-text="preview?.selected_count ?? 0"></span> contacts match after exclusions.
                        </p>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 text-xl text-slate-600" x-on:click="previewContactsOpen = false" aria-label="Close">×</button>
                </header>

                <div class="max-h-[68dvh] overflow-y-auto px-5 py-4 sm:px-6">
                    <template x-if="preview?.contacts_truncated">
                        <p class="mb-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Showing the first 100 matching contacts. The saved Broadcast still uses the full resolved audience.
                        </p>
                    </template>

                    <div class="divide-y divide-slate-100">
                        <template x-for="contact in (preview?.contacts ?? [])" :key="contact.id">
                            <div class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-slate-900" x-text="contact.name"></div>
                                    <div class="mt-1 truncate text-xs text-slate-500">
                                        <span x-text="contact.email || contact.phone || `Contact #${contact.id}`"></span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    x-on:click="removePreviewContact(contact.id)"
                                >
                                    Remove
                                </button>
                            </div>
                        </template>
                    </div>

                    <template x-if="(preview?.contacts ?? []).length === 0">
                        <p class="py-8 text-center text-sm text-slate-500">No contacts match the current audience.</p>
                    </template>
                </div>
            </section>
        </div>
    </template>
</section>