@php
    $currentFilterType = $recipientFilterType ?? old('recipient_filter_type', 'criteria');
    $currentCriteria = $recipientCriteria ?? old('recipient_criteria', []);
    $currentTag = $recipientTag ?? old('recipient_tag');
    $currentExcludeBroadcastIds = collect($excludeBroadcastIds ?? old('exclude_broadcast_ids', []))
        ->map(fn ($id) => (int) $id)
        ->all();
    $currentExcludeBroadcastStatuses = $excludeBroadcastStatuses ?? old('exclude_broadcast_statuses', [
        \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED,
        \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT,
    ]);
@endphp

<section
    class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
    x-data="{
        recipientFilterType: @js($currentFilterType),
        preview: null,
        previewLoading: false,
        previewError: null,
        async previewAudience() {
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
            } catch (error) {
                this.preview = null;
                this.previewError = error?.message ?? 'Audience preview failed.';
            } finally {
                this.previewLoading = false;
            }
        },
    }"
>
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">1. Who</p>
        <h3 class="mt-1 text-base font-semibold text-slate-900">Choose the audience first</h3>
        <p class="mt-1 text-sm text-slate-600">
            Criteria are combined with AND. Multiple choices inside one criterion are combined with OR.
        </p>
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
            @if($currentFilterType === 'tag')
                <option value="tag">Legacy tag filter</option>
            @endif
        </x-ui.form.select>
        <x-ui.form.error name="recipient_filter_type" />
    </div>

    <div x-show="recipientFilterType === 'criteria'" x-cloak class="grid gap-4 lg:grid-cols-2">
        @foreach($audienceCriteria as $criterion)
            @php
                $key = $criterion['key'];
                $selectedValues = collect($currentCriteria[$key] ?? [])
                    ->map(fn ($value) => (string) $value)
                    ->all();
            @endphp

            @if(($criterion['options'] ?? []) !== [])
                <div>
                    <x-ui.form.label for="recipient_criteria_{{ $key }}">
                        {{ $criterion['label'] }}
                    </x-ui.form.label>

                    <select
                        id="recipient_criteria_{{ $key }}"
                        name="recipient_criteria[{{ $key }}][]"
                        multiple
                        class="mt-1 block min-h-28 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                    >
                        @foreach($criterion['options'] as $option)
                            <option
                                value="{{ $option['value'] }}"
                                @selected(in_array((string) $option['value'], $selectedValues, true))
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

    @if($currentFilterType === 'tag')
        <div x-show="recipientFilterType === 'tag'" x-cloak>
            <x-ui.form.label for="recipient_tag">Contact tag</x-ui.form.label>
            <x-ui.form.input
                id="recipient_tag"
                name="recipient_tag"
                value="{{ $currentTag }}"
            />
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button
            type="button"
            variant="secondary"
            x-on:click="previewAudience()"
            x-bind:disabled="previewLoading"
        >
            <span x-show="! previewLoading">Preview audience</span>
            <span x-show="previewLoading" x-cloak>Checking…</span>
        </x-ui.button>

        <p class="text-xs text-slate-500">
            Preview before composing so you can see audience size and prior Broadcast overlap.
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
                    class="mt-1 block min-h-28 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                >
                    @foreach($excludableBroadcasts as $excludableBroadcast)
                        <option
                            value="{{ $excludableBroadcast->id }}"
                            @selected(in_array($excludableBroadcast->id, $currentExcludeBroadcastIds, true))
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
                        value="{{ \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED }}"
                        @checked(in_array(\App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED, $currentExcludeBroadcastStatuses, true))
                        class="rounded border-slate-300"
                    >
                    Scheduled
                </label>

                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="exclude_broadcast_statuses[]"
                        value="{{ \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT }}"
                        @checked(in_array(\App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT, $currentExcludeBroadcastStatuses, true))
                        class="rounded border-slate-300"
                    >
                    Sent
                </label>
            </div>
        </details>
    @endif
</section>