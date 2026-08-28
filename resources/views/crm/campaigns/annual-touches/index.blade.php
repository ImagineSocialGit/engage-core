<x-layouts.crm
    title="Annual Touches"
    heading="Annual Touches"
    subheading="Set recurring birthday and calendar-date messages for the contacts you choose."
    module="campaigns"
>
    @php
        $editing = $editingProgram instanceof \App\Modules\Campaigns\Models\CampaignTouchProgram;
        $defaultAudienceMode = old('audience_mode', $audience['mode'] ?? 'all');
        $selectedAudienceCriteria = old('audience_criteria', $audience['selected'] ?? []);
        $selectedAudienceCriteria = is_array($selectedAudienceCriteria) ? $selectedAudienceCriteria : [];
        $selectedAudienceContactIds = old('audience_contact_ids', $audience['contact_ids'] ?? []);
        $selectedAudienceContactIds = is_array($selectedAudienceContactIds) ? array_values($selectedAudienceContactIds) : [];
        $selectedAudienceExcludeCriteria = old('audience_exclude_criteria', $audience['exclude_selected'] ?? []);
        $selectedAudienceExcludeCriteria = is_array($selectedAudienceExcludeCriteria) ? $selectedAudienceExcludeCriteria : [];
        $selectedAudienceExcludeContactIds = old('audience_exclude_contact_ids', $audience['exclude_contact_ids'] ?? []);
        $selectedAudienceExcludeContactIds = is_array($selectedAudienceExcludeContactIds) ? array_values($selectedAudienceExcludeContactIds) : [];
        $defaultRepeatYears = old('repeat_years', $editing ? $editingProgram->repeat_years : 10);
        $defaultStartsOn = old('starts_on', $editing && $editingProgram->starts_on ? $editingProgram->starts_on->toDateString() : '');
        $defaultActive = old('is_active', $editing ? $editingProgram->is_active : true);

        $initialTouches = old('touches');

        if (! is_array($initialTouches)) {
            if ($editing) {
                $initialTouches = $editingProgram->touchDates
                    ->where('is_active', true)
                    ->values()
                    ->map(function ($touchDate) {
                        $variants = $touchDate->variants->where('is_active', true)->keyBy('channel');

                        return [
                            'id' => $touchDate->getKey(),
                            'name' => $touchDate->name ?: 'Annual touch',
                            'source_type' => $touchDate->source_type === \App\Modules\Campaigns\Models\CampaignTouchDate::SOURCE_CONTACT_FIELD
                                && $touchDate->source_key === 'birthday'
                                    ? 'birthday'
                                    : 'fixed_date',
                            'month' => $touchDate->month,
                            'day' => $touchDate->day,
                            'send_time' => is_string($touchDate->send_time)
                                ? substr($touchDate->send_time, 0, 5)
                                : '09:00',
                            'email_template_preset_id' => $variants->get('email')?->message_template_preset_id,
                            'sms_template_preset_id' => $variants->get('sms')?->message_template_preset_id,
                        ];
                    })
                    ->all();
            } else {
                $initialTouches = [[
                    'id' => null,
                    'name' => 'Birthday',
                    'source_type' => 'birthday',
                    'month' => null,
                    'day' => null,
                    'send_time' => '09:00',
                    'email_template_preset_id' => null,
                    'sms_template_preset_id' => null,
                ]];
            }
        }

    @endphp

    <div class="min-w-0 space-y-6">
        @if(session('status'))
            <div class="break-words rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <div class="font-bold">Please fix the highlighted annual-touch setup.</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.campaigns.index') }}"
                class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50"
            >
                Back to Campaigns
            </a>

            @if($editing)
                <a
                    href="{{ route('crm.campaigns.annual-touches.index') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800"
                >
                    Add another program
                </a>
            @endif
        </div>

        <section class="rounded-3xl border border-rose-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Recurring nurture</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    Have recurring annual touch-base dates
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Choose who this applies to, how many years it runs, then add annual dates. Messaging still decides whether each Email or SMS is eligible to send.
                </p>
            </div>

            <form
                method="POST"
                action="{{ $editing
                    ? route('crm.campaigns.annual-touches.update', $editingProgram)
                    : route('crm.campaigns.annual-touches.store') }}"
                class="mt-6 space-y-6"
                x-ref="annualTouchForm"
                x-data="{
                    rows: @js(array_values($initialTouches)),
                    newEmailTemplates: [],
                    newSmsTemplates: [],
                    audienceMode: @js((string) $defaultAudienceMode),
                    audienceContacts: @js($audience['selected_contacts'] ?? []),
                    excludedAudienceContacts: @js($audience['excluded_contacts'] ?? []),
                    initialAudienceContactIds: @js(array_map('intval', $selectedAudienceContactIds)),
                    initialExcludedAudienceContactIds: @js(array_map('intval', $selectedAudienceExcludeContactIds)),
                    audienceSearch: {
                        include: { query: '', results: [], busy: false, error: '' },
                        exclude: { query: '', results: [], busy: false, error: '' },
                    },
                    matchingAudienceCount: @js((int) ($audience['matching_count'] ?? 0)),
                    audienceSummary: @js((string) ($audience['summary'] ?? 'All eligible contacts')),
                    previewingAudience: false,
                    audiencePreviewError: '',
                    templateCreator: {
                        open: false,
                        saving: false,
                        error: '',
                        channel: 'email',
                        rowIndex: 0,
                        name: '',
                        subject: '',
                        body: '',
                        message: '',
                        activeField: 'body',
                    },
                    init() {
                        this.rows = this.rows.map((row) => ({
                            ...row,
                            email_template_preset_id: row.email_template_preset_id ? String(row.email_template_preset_id) : '',
                            sms_template_preset_id: row.sms_template_preset_id ? String(row.sms_template_preset_id) : '',
                        }));

                        this.hydrateContactSelections('include', this.initialAudienceContactIds);
                        this.hydrateContactSelections('exclude', this.initialExcludedAudienceContactIds);
                    },
                    mergeContacts(existing, incoming) {
                        const merged = [...existing];
                        const ids = new Set(merged.map((contact) => Number(contact.id)));

                        incoming.forEach((contact) => {
                            const id = Number(contact.id);

                            if (! Number.isInteger(id) || id < 1 || ids.has(id)) {
                                return;
                            }

                            ids.add(id);
                            merged.push({ id, label: contact.label ?? `Contact #${id}` });
                        });

                        return merged;
                    },
                    async hydrateContactSelections(kind, ids) {
                        const normalizedIds = [...new Set((ids ?? [])
                            .map((id) => Number(id))
                            .filter((id) => Number.isInteger(id) && id > 0))];

                        if (normalizedIds.length === 0) {
                            return;
                        }

                        const selected = kind === 'exclude'
                            ? this.excludedAudienceContacts
                            : this.audienceContacts;
                        const selectedIds = new Set(selected.map((contact) => Number(contact.id)));
                        const missingIds = normalizedIds.filter((id) => ! selectedIds.has(id));

                        if (missingIds.length === 0) {
                            return;
                        }

                        const url = new URL(@js(route('crm.contacts.lookup')), window.location.origin);
                        missingIds.forEach((id) => url.searchParams.append('ids[]', String(id)));

                        try {
                            const response = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json();

                            if (! response.ok) {
                                return;
                            }

                            const contacts = Array.isArray(payload.contacts) ? payload.contacts : [];

                            if (kind === 'exclude') {
                                this.excludedAudienceContacts = this.mergeContacts(this.excludedAudienceContacts, contacts);
                            } else {
                                this.audienceContacts = this.mergeContacts(this.audienceContacts, contacts);
                            }
                        } catch (error) {
                            // Validation remains authoritative if hydration fails.
                        }
                    },
                    async searchAudienceContacts(kind) {
                        const state = this.audienceSearch[kind];
                        const query = String(state.query ?? '').trim();

                        state.error = '';
                        state.results = [];

                        if (query.length < 2) {
                            state.error = 'Enter at least 2 characters.';
                            return;
                        }

                        state.busy = true;

                        try {
                            const url = new URL(@js(route('crm.contacts.lookup')), window.location.origin);
                            url.searchParams.set('q', query);
                            const response = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json();

                            if (! response.ok) {
                                throw new Error(payload.message || 'Contact search failed.');
                            }

                            state.results = Array.isArray(payload.contacts) ? payload.contacts : [];
                        } catch (error) {
                            state.error = error.message || 'Contact search failed.';
                        } finally {
                            state.busy = false;
                        }
                    },
                    addAudienceContact(kind, contact) {
                        if (kind === 'exclude') {
                            this.excludedAudienceContacts = this.mergeContacts(this.excludedAudienceContacts, [contact]);
                        } else {
                            this.audienceContacts = this.mergeContacts(this.audienceContacts, [contact]);
                        }
                    },
                    removeAudienceContact(kind, id) {
                        id = Number(id);

                        if (kind === 'exclude') {
                            this.excludedAudienceContacts = this.excludedAudienceContacts.filter((contact) => Number(contact.id) !== id);
                        } else {
                            this.audienceContacts = this.audienceContacts.filter((contact) => Number(contact.id) !== id);
                        }
                    },
                    async previewAudience() {
                        this.previewingAudience = true;
                        this.audiencePreviewError = '';

                        try {
                            const data = new FormData(this.$refs.annualTouchForm);
                            const response = await fetch(@js(route('crm.campaigns.annual-touches.audience-preview')), {
                                method: 'POST',
                                body: data,
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json();

                            if (! response.ok) {
                                const messages = Object.values(payload.errors ?? {}).flat();
                                throw new Error(messages[0] ?? payload.message ?? 'Unable to preview this audience.');
                            }

                            this.matchingAudienceCount = Number(payload.matching_count ?? 0);
                            this.audienceSummary = payload.summary ?? this.audienceSummary;
                        } catch (error) {
                            this.audiencePreviewError = error.message || 'Unable to preview this audience.';
                        } finally {
                            this.previewingAudience = false;
                        }
                    },
                    addRow() {
                        this.rows.push({
                            id: null,
                            name: '',
                            source_type: 'fixed_date',
                            month: null,
                            day: null,
                            send_time: '09:00',
                            email_template_preset_id: null,
                            sms_template_preset_id: null,
                        });
                    },
                    removeRow(index) {
                        this.rows.splice(index, 1);
                    },
                    openTemplateCreator(channel, index) {
                        this.templateCreator = {
                            open: true,
                            saving: false,
                            error: '',
                            channel,
                            rowIndex: index,
                            name: this.rows[index]?.name ?? '',
                            subject: '',
                            body: '',
                            message: '',
                            activeField: channel === 'sms' ? 'message' : 'body',
                        };
                    },
                    closeTemplateCreator() {
                        if (! this.templateCreator.saving) {
                            this.templateCreator.open = false;
                        }
                    },
                    setActiveTemplateField(field) {
                        this.templateCreator.activeField = field;
                    },
                    insertField(syntax) {
                        const field = this.templateCreator.channel === 'sms'
                            ? 'message'
                            : (['subject', 'body'].includes(this.templateCreator.activeField)
                                ? this.templateCreator.activeField
                                : 'body');
                        const element = this.$root.querySelector(`[data-template-authoring-field='${field}']`);
                        const currentValue = String(this.templateCreator[field] ?? '');
                        const selectionStart = element && Number.isInteger(element.selectionStart)
                            ? element.selectionStart
                            : currentValue.length;
                        const selectionEnd = element && Number.isInteger(element.selectionEnd)
                            ? element.selectionEnd
                            : selectionStart;

                        this.templateCreator[field] = currentValue.slice(0, selectionStart)
                            + syntax
                            + currentValue.slice(selectionEnd);

                        this.$nextTick(() => {
                            const updatedElement = this.$root.querySelector(`[data-template-authoring-field='${field}']`);

                            if (! updatedElement) {
                                return;
                            }

                            const nextPosition = selectionStart + syntax.length;
                            updatedElement.focus();

                            if (typeof updatedElement.setSelectionRange === 'function') {
                                updatedElement.setSelectionRange(nextPosition, nextPosition);
                            }
                        });
                    },
                    async createTemplate() {
                        this.templateCreator.saving = true;
                        this.templateCreator.error = '';

                        try {
                            const response = await fetch(@js(route('crm.campaigns.annual-touches.message-templates.store')), {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': @js(csrf_token()),
                                },
                                body: JSON.stringify({
                                    channel: this.templateCreator.channel,
                                    name: this.templateCreator.name,
                                    subject: this.templateCreator.subject,
                                    body: this.templateCreator.body,
                                    message: this.templateCreator.message,
                                }),
                            });

                            const result = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                const validationMessages = Object.values(result.errors ?? {}).flat();
                                this.templateCreator.error = validationMessages[0] ?? result.message ?? 'The message template could not be created.';
                                return;
                            }

                            const option = { id: String(result.id), name: result.name };

                            if (result.channel === 'sms') {
                                this.newSmsTemplates.push(option);
                                this.newSmsTemplates.sort((a, b) => a.name.localeCompare(b.name));
                                this.$nextTick(() => {
                                    this.rows[this.templateCreator.rowIndex].sms_template_preset_id = option.id;
                                });
                            } else {
                                this.newEmailTemplates.push(option);
                                this.newEmailTemplates.sort((a, b) => a.name.localeCompare(b.name));
                                this.$nextTick(() => {
                                    this.rows[this.templateCreator.rowIndex].email_template_preset_id = option.id;
                                });
                            }

                            this.templateCreator.open = false;
                        } catch (error) {
                            this.templateCreator.error = 'The message template could not be created. Try again.';
                        } finally {
                            this.templateCreator.saving = false;
                        }
                    },
                }"
            >
                @csrf
                @if($editing)
                    @method('PUT')
                @endif

                @if($editing)
                    <input type="hidden" name="program_id" value="{{ $editingProgram->getKey() }}">
                @endif

                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-slate-950">Audience</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">Choose who receives these annual messages. Contact Status is optional; Messaging still checks channel consent, suppression, destination, and provider availability for every send.</p>
                        </div>
                        <div class="shrink-0 text-left sm:text-right">
                            <p class="text-sm font-semibold text-slate-950"><span x-text="Number(matchingAudienceCount).toLocaleString()"></span> matching now</p>
                            <p class="mt-1 text-xs text-slate-500" x-text="audienceSummary"></p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.35fr)]">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-900">Who should get these messages?</span>
                            <select
                                name="audience_mode"
                                x-model="audienceMode"
                                required
                                class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                            >
                                @foreach(($audience['modes'] ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected((string) $defaultAudienceMode === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="self-end">
                            <button
                                type="button"
                                x-on:click="previewAudience()"
                                x-bind:disabled="previewingAudience"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                            >
                                <span x-show="!previewingAudience">Preview audience</span>
                                <span x-show="previewingAudience">Checking…</span>
                            </button>
                        </div>
                    </div>

                    <p x-show="audiencePreviewError" x-text="audiencePreviewError" class="mt-3 text-sm font-semibold text-red-600"></p>
                    @error('audience_mode')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror

                    <div x-show="audienceMode === 'criteria'" x-cloak class="mt-5 space-y-4">
                        <div>
                            <p class="text-sm font-bold text-slate-900">Match these conditions</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">A contact must match every condition group you use. Within a group, matching any selected value is enough.</p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            @forelse(($audience['criteria'] ?? []) as $criterion)
                                @php
                                    $criterionKey = (string) $criterion['key'];
                                    $selectedValues = $selectedAudienceCriteria[$criterionKey] ?? [];
                                    $selectedValues = is_array($selectedValues) ? $selectedValues : [];
                                @endphp
                                <fieldset class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <legend class="px-1 text-sm font-bold text-slate-950">{{ $criterion['label'] }}</legend>
                                    @if(filled($criterion['help'] ?? null))
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $criterion['help'] }}</p>
                                    @endif
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @forelse($criterion['options'] as $option)
                                            <label class="flex min-h-11 items-start gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800 hover:bg-slate-50">
                                                <input
                                                    type="checkbox"
                                                    name="audience_criteria[{{ $criterionKey }}][]"
                                                    value="{{ $option['value'] }}"
                                                    @checked(in_array($option['value'], $selectedValues, true))
                                                    class="mt-0.5 rounded border-slate-300 text-rose-700 focus:ring-rose-600"
                                                >
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @empty
                                            <p class="text-sm text-slate-500">No available values.</p>
                                        @endforelse
                                    </div>
                                    @error('audience_criteria.'.$criterionKey)<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </fieldset>
                            @empty
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 lg:col-span-2">
                                    No audience conditions are currently available. Choose all eligible contacts or specific contacts instead.
                                </div>
                            @endforelse
                        </div>
                        @error('audience_criteria')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div x-show="audienceMode === 'contacts'" x-cloak class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                        <p class="text-sm font-bold text-slate-900">Specific contacts</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Search by name, email, or phone and add the contacts who should receive these annual messages.</p>

                        <template x-for="contact in audienceContacts" :key="`audience-contact-${contact.id}`">
                            <input type="hidden" name="audience_contact_ids[]" :value="contact.id">
                        </template>

                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <input
                                type="search"
                                x-model="audienceSearch.include.query"
                                x-on:keydown.enter.prevent="searchAudienceContacts('include')"
                                class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                                placeholder="Search contacts"
                            >
                            <button type="button" x-on:click="searchAudienceContacts('include')" x-bind:disabled="audienceSearch.include.busy" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                                <span x-show="!audienceSearch.include.busy">Search</span>
                                <span x-show="audienceSearch.include.busy">Searching…</span>
                            </button>
                        </div>
                        <p x-show="audienceSearch.include.error" x-text="audienceSearch.include.error" class="mt-2 text-xs font-semibold text-red-600"></p>

                        <div x-show="audienceSearch.include.results.length" class="mt-3 grid gap-2 sm:grid-cols-2">
                            <template x-for="contact in audienceSearch.include.results" :key="`audience-result-${contact.id}`">
                                <button type="button" x-on:click="addAudienceContact('include', contact)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm text-slate-800 hover:bg-slate-100" x-text="contact.label"></button>
                            </template>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <template x-for="contact in audienceContacts" :key="`audience-selected-${contact.id}`">
                                <span class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-900 ring-1 ring-rose-200">
                                    <span x-text="contact.label"></span>
                                    <button type="button" x-on:click="removeAudienceContact('include', contact.id)" class="font-black text-rose-700" aria-label="Remove selected contact">×</button>
                                </span>
                            </template>
                            <p x-show="audienceContacts.length === 0" class="text-sm text-slate-500">No contacts selected yet.</p>
                        </div>
                        @error('audience_contact_ids')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <details class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                        <summary class="cursor-pointer text-sm font-bold text-slate-900">Exclude some contacts <span class="font-normal text-slate-500">(optional)</span></summary>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Exclusions are applied after the main audience. Matching any exclusion group removes a contact. If an optional feature that owns an exclusion is unavailable, the program fails closed rather than accidentally sending too broadly.</p>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @foreach(($audience['criteria'] ?? []) as $criterion)
                                @php
                                    $criterionKey = (string) $criterion['key'];
                                    $selectedValues = $selectedAudienceExcludeCriteria[$criterionKey] ?? [];
                                    $selectedValues = is_array($selectedValues) ? $selectedValues : [];
                                @endphp
                                <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <legend class="px-1 text-sm font-bold text-slate-950">Exclude by {{ $criterion['label'] }}</legend>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @forelse($criterion['options'] as $option)
                                            <label class="flex min-h-11 items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800">
                                                <input
                                                    type="checkbox"
                                                    name="audience_exclude_criteria[{{ $criterionKey }}][]"
                                                    value="{{ $option['value'] }}"
                                                    @checked(in_array($option['value'], $selectedValues, true))
                                                    class="mt-0.5 rounded border-slate-300 text-rose-700 focus:ring-rose-600"
                                                >
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @empty
                                            <p class="text-sm text-slate-500">No available values.</p>
                                        @endforelse
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-bold text-slate-900">Exclude specific contacts</p>
                            <template x-for="contact in excludedAudienceContacts" :key="`excluded-contact-${contact.id}`">
                                <input type="hidden" name="audience_exclude_contact_ids[]" :value="contact.id">
                            </template>
                            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                <input type="search" x-model="audienceSearch.exclude.query" x-on:keydown.enter.prevent="searchAudienceContacts('exclude')" class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900" placeholder="Search contacts to exclude">
                                <button type="button" x-on:click="searchAudienceContacts('exclude')" x-bind:disabled="audienceSearch.exclude.busy" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50">Search</button>
                            </div>
                            <p x-show="audienceSearch.exclude.error" x-text="audienceSearch.exclude.error" class="mt-2 text-xs font-semibold text-red-600"></p>
                            <div x-show="audienceSearch.exclude.results.length" class="mt-3 grid gap-2 sm:grid-cols-2">
                                <template x-for="contact in audienceSearch.exclude.results" :key="`excluded-result-${contact.id}`">
                                    <button type="button" x-on:click="addAudienceContact('exclude', contact)" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-sm text-slate-800 hover:bg-slate-100" x-text="contact.label"></button>
                                </template>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <template x-for="contact in excludedAudienceContacts" :key="`excluded-selected-${contact.id}`">
                                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-800">
                                        <span x-text="contact.label"></span>
                                        <button type="button" x-on:click="removeAudienceContact('exclude', contact.id)" class="font-black text-slate-600" aria-label="Remove excluded contact">×</button>
                                    </span>
                                </template>
                            </div>
                        </div>

                    </details>

                    @if(($audience['unavailable_criteria'] ?? []) !== [] || ($audience['unavailable_exclude_criteria'] ?? []) !== [])
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            Some saved audience conditions belong to features that are not currently available. They are preserved, and this program will not send until those conditions can be resolved again.
                        </div>
                    @endif
                </section>

                <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                    <div class="min-w-0 lg:col-span-2">
                        <p class="text-sm font-bold text-slate-900">How long should it repeat?</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">This controls the annual recurrence window, not how often the contact can receive other messages.</p>
                    </div>
                    <div class="min-w-0">
                        <label class="text-sm font-bold text-slate-900">Repeat for</label>
                        <div class="mt-2 flex items-center gap-2">
                            <input
                                type="number"
                                min="1"
                                max="50"
                                name="repeat_years"
                                value="{{ $defaultRepeatYears }}"
                                required
                                class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                            >
                            <span class="text-sm font-semibold text-slate-600">years</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-bold text-slate-900">Start date <span class="font-normal text-slate-500">(optional)</span></label>
                        <input
                            type="date"
                            name="starts_on"
                            value="{{ $defaultStartsOn }}"
                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                        >
                    </div>

                    <label class="flex min-h-11 items-center gap-3 self-end rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <input type="hidden" name="is_active" value="0">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            @checked((bool) $defaultActive)
                            class="h-4 w-4 rounded border-slate-300"
                        >
                        <span>
                            <span class="block text-sm font-bold text-slate-900">Enabled</span>
                            <span class="block text-xs text-slate-500">This annual-touch program can send its configured messages when each date becomes due.</span>
                        </span>
                    </label>
                </div>

                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-950">Annual dates</h3>
                            <p class="mt-1 text-sm text-slate-600">Birthday uses the Contact birthday field. Fixed dates are useful for holidays or client-defined annual touches.</p>
                        </div>
                        <button
                            type="button"
                            @click="addRow()"
                            class="inline-flex min-h-11 items-center justify-center rounded-full border border-rose-300 bg-rose-50 px-5 text-sm font-bold text-rose-900 hover:bg-rose-100"
                        >
                            Add date
                        </button>
                    </div>

                    <template x-for="(row, index) in rows" :key="row.id ?? `new-${index}`">
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                            <input type="hidden" :name="`touches[${index}][id]`" x-model="row.id">

                            <div class="grid min-w-0 gap-4 lg:grid-cols-12">
                                <div class="min-w-0 lg:col-span-3">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Name</label>
                                    <input
                                        type="text"
                                        required
                                        :name="`touches[${index}][name]`"
                                        x-model="row.name"
                                        placeholder="Birthday, Christmas, Anniversary..."
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                </div>

                                <div class="min-w-0 lg:col-span-2">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Date type</label>
                                    <select
                                        :name="`touches[${index}][source_type]`"
                                        x-model="row.source_type"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="birthday">Contact birthday</option>
                                        <option value="fixed_date">Fixed annual date</option>
                                    </select>
                                </div>

                                <div class="min-w-0 lg:col-span-2" x-show="row.source_type === 'fixed_date'">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Month / day</label>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <select
                                            :name="`touches[${index}][month]`"
                                            x-model="row.month"
                                            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-sm text-slate-900"
                                        >
                                            <option value="">Month</option>
                                            @foreach(range(1, 12) as $month)
                                                <option value="{{ $month }}">{{ \Carbon\Carbon::create(2000, $month, 1)->format('M') }}</option>
                                            @endforeach
                                        </select>
                                        <select
                                            :name="`touches[${index}][day]`"
                                            x-model="row.day"
                                            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-sm text-slate-900"
                                        >
                                            <option value="">Day</option>
                                            @foreach(range(1, 31) as $day)
                                                <option value="{{ $day }}">{{ $day }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="min-w-0 lg:col-span-2">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Send time</label>
                                    <input
                                        type="time"
                                        required
                                        :name="`touches[${index}][send_time]`"
                                        x-model="row.send_time"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                </div>

                                <div class="min-w-0 lg:col-span-3 lg:text-right">
                                    <button
                                        type="button"
                                        @click="removeRow(index)"
                                        x-show="rows.length > 1"
                                        class="mt-6 inline-flex min-h-10 items-center justify-center rounded-full border border-red-200 bg-white px-4 text-sm font-bold text-red-700 hover:bg-red-50"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-xs leading-5 text-slate-600">
                                Only saved reusable marketing messages appear here. Campaign steps, webinar reminders, reply acknowledgements, and other lifecycle-owned messages are intentionally excluded.
                            </div>

                            <div class="mt-4 grid min-w-0 gap-4 lg:grid-cols-2">
                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Saved email message</label>
                                        <button
                                            type="button"
                                            @click="openTemplateCreator('email', index)"
                                            class="text-xs font-bold text-rose-700 hover:text-rose-900"
                                        >
                                            + Add new message
                                        </button>
                                    </div>
                                    <select
                                        :name="`touches[${index}][email_template_preset_id]`"
                                        x-model="row.email_template_preset_id"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="">No email</option>
                                        @foreach($emailTemplates as $template)
                                            <option
                                                value="{{ $template->getKey() }}"
                                                data-template-option-id="{{ $template->getKey() }}"
                                            >{{ $template->name }}</option>
                                        @endforeach
                                        <template x-for="template in newEmailTemplates" :key="`new-email-${template.id}`">
                                            <option :value="template.id" x-text="template.name" data-new-template-option></option>
                                        </template>
                                    </select>
                                </div>

                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Saved SMS message</label>
                                        <button
                                            type="button"
                                            @click="openTemplateCreator('sms', index)"
                                            class="text-xs font-bold text-rose-700 hover:text-rose-900"
                                        >
                                            + Add new message
                                        </button>
                                    </div>
                                    <select
                                        :name="`touches[${index}][sms_template_preset_id]`"
                                        x-model="row.sms_template_preset_id"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="">No SMS</option>
                                        @foreach($smsTemplates as $template)
                                            <option
                                                value="{{ $template->getKey() }}"
                                                data-template-option-id="{{ $template->getKey() }}"
                                            >{{ $template->name }}</option>
                                        @endforeach
                                        <template x-for="template in newSmsTemplates" :key="`new-sms-${template.id}`">
                                            <option :value="template.id" x-text="template.name" data-new-template-option></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <div
                    x-show="templateCreator.open"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
                    @keydown.escape.window="closeTemplateCreator()"
                    @message-field-insert="insertField($event.detail.syntax)"
                >
                    <div
                        class="w-full max-w-2xl rounded-3xl bg-white p-5 shadow-2xl sm:p-7"
                        @click.outside="closeTemplateCreator()"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Annual Touch message</p>
                                <h3 class="mt-2 text-xl font-semibold text-slate-950" x-text="templateCreator.channel === 'sms' ? 'Create SMS message' : 'Create email message'"></h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Messaging fills in the standalone annual-touch purpose, scope, dispatch context, catalog grouping, and valid fields automatically.
                                </p>
                            </div>
                            <button type="button" @click="closeTemplateCreator()" class="text-sm font-bold text-slate-500 hover:text-slate-900">Close</button>
                        </div>

                        <div x-show="templateCreator.error" x-text="templateCreator.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800"></div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <label class="text-sm font-bold text-slate-900">Message name</label>
                                <input type="text" x-model="templateCreator.name" maxlength="191" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900" placeholder="Birthday greeting">
                            </div>

                            <template x-if="templateCreator.channel === 'email'">
                                <div class="space-y-4">
                                    <div>
                                        <label class="text-sm font-bold text-slate-900">Subject</label>
                                        <input
                                            type="text"
                                            x-model="templateCreator.subject"
                                            @focus="setActiveTemplateField('subject')"
                                            maxlength="255"
                                            data-template-authoring-field="subject"
                                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                                        >
                                    </div>
                                    <div>
                                        <label class="text-sm font-bold text-slate-900">Body</label>
                                        <textarea
                                            x-model="templateCreator.body"
                                            @focus="setActiveTemplateField('body')"
                                            rows="9"
                                            data-template-authoring-field="body"
                                            class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        ></textarea>
                                    </div>
                                </div>
                            </template>

                            <template x-if="templateCreator.channel === 'sms'">
                                <div>
                                    <label class="text-sm font-bold text-slate-900">Message</label>
                                    <textarea
                                        x-model="templateCreator.message"
                                        @focus="setActiveTemplateField('message')"
                                        rows="6"
                                        maxlength="1600"
                                        data-template-authoring-field="message"
                                        class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                    ></textarea>
                                </div>
                            </template>

                            <x-messaging.available-fields
                                :groups="$annualTouchAvailableFields"
                                class="mt-5"
                            />
                        </div>

                        <div class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                            <button type="button" @click="closeTemplateCreator()" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-bold text-slate-800 hover:bg-slate-50">Cancel</button>
                            <button type="button" @click="createTemplate()" :disabled="templateCreator.saving" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                                <span x-text="templateCreator.saving ? 'Creating…' : 'Create and use message'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        Choose a saved reusable message or create one here. New messages automatically receive the standalone annual-touch context and are selected in this row after creation.
                    </p>
                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800"
                    >
                        {{ $editing ? 'Save annual touches' : 'Create annual touches' }}
                    </button>
                </div>
            </form>
        </section>

        @if($programs->isNotEmpty())
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                    <h2 class="text-lg font-semibold text-slate-950">Configured annual-touch programs</h2>
                </div>

                @foreach($programs as $program)
                    <article class="border-b border-slate-200 p-4 last:border-b-0 sm:p-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="break-words font-semibold text-slate-950">{{ $programAudienceSummaries[$program->getKey()] ?? 'Audience unavailable' }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $program->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $program->is_active ? 'Enabled' : 'Off' }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ $programAudienceSummaries[$program->getKey()] ?? 'Audience unavailable' }} · {{ $program->repeat_years }} years
                                    · {{ $program->touchDates->where('is_active', true)->count() }} annual {{ \Illuminate\Support\Str::plural('date', $program->touchDates->where('is_active', true)->count()) }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <a
                                    href="{{ route('crm.campaigns.annual-touches.index', ['edit' => $program->getKey()]) }}"
                                    class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-bold text-slate-800 hover:bg-slate-50"
                                >
                                    Edit
                                </a>
                                @if($program->is_active)
                                    <form method="POST" action="{{ route('crm.campaigns.annual-touches.destroy', $program) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-red-200 px-5 text-sm font-bold text-red-700 hover:bg-red-50 sm:w-auto"
                                        >
                                            Turn off
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts.crm>