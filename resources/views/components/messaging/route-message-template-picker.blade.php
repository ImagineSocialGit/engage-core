@props([
    'field' => [],
    'fieldSuffix' => '',
    'value' => null,
])

@php
    $name = (string) ($field['name'] ?? 'message_template_preset_id');
    $label = (string) ($field['label'] ?? 'Message template');
    $required = (bool) ($field['required'] ?? true);
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    $createUrl = (string) ($field['create_url'] ?? '');
    $availableFields = is_array($field['available_fields'] ?? null) ? $field['available_fields'] : [];
    $availableChannels = is_array($field['available_channels'] ?? null) ? $field['available_channels'] : ['email'];
    $purposes = is_array($field['purposes'] ?? null) ? $field['purposes'] : [];
    $activeWhen = is_array($field['active_when'] ?? null) ? $field['active_when'] : null;
    $fieldId = $name.'-'.$fieldSuffix;
@endphp

<div
    class="space-y-3"
    x-data="{
        instanceId: @js($fieldSuffix),
        selected: @js((string) ($value ?? '')),
        createdTemplates: [],
        createOpen: false,
        saving: false,
        error: '',
        channel: @js(in_array('email', $availableChannels, true) ? 'email' : ($availableChannels[0] ?? 'email')),
        purpose: 'marketing',
        templateName: '',
        subject: '',
        body: '',
        message: '',
        lastField: 'body',
        openCreateMessage() {
            this.error = '';
            this.createOpen = true;
            this.$nextTick(() => this.$refs.templateName?.focus());
        },
        closeCreateMessage() {
            if (this.saving) return;
            this.createOpen = false;
            this.error = '';
        },
        resetCreateMessage() {
            this.templateName = '';
            this.subject = '';
            this.body = '';
            this.message = '';
            this.lastField = this.channel === 'sms' ? 'message' : 'body';
        },
        insertField(syntax) {
            const fallback = this.channel === 'sms' ? 'message' : 'body';
            const field = this.lastField || fallback;
            const container = document.querySelector(`[data-route-message-authoring-id='${this.instanceId}']`);
            const element = container?.querySelector(`[data-template-authoring-field='${field}']`);
            if (! element) return;

            const start = Number.isInteger(element.selectionStart) ? element.selectionStart : element.value.length;
            const end = Number.isInteger(element.selectionEnd) ? element.selectionEnd : start;
            element.value = element.value.slice(0, start) + syntax + element.value.slice(end);
            element.dispatchEvent(new Event('input', { bubbles: true }));
            this.$nextTick(() => {
                element.focus();
                const cursor = start + syntax.length;
                element.setSelectionRange?.(cursor, cursor);
            });
        },
        async saveMessage() {
            this.saving = true;
            this.error = '';

            const payload = {
                channel: this.channel,
                purpose: this.purpose,
                name: this.templateName,
                subject: this.subject,
                body: this.body,
                message: this.message,
            };

            try {
                const response = await fetch(@js($createUrl), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (! response.ok) {
                    const errors = data.errors || {};
                    const first = Object.values(errors).flat()[0];
                    throw new Error(first || data.message || 'Unable to create the message template.');
                }

                this.createdTemplates.push({
                    value: String(data.id),
                    label: data.name,
                    description: data.description || '',
                });
                this.createOpen = false;
                this.resetCreateMessage();
                this.$nextTick(() => { this.selected = String(data.id); });
            } catch (error) {
                this.error = error?.message || 'Unable to create the message template.';
            } finally {
                this.saving = false;
            }
        },
    }"
    x-on:message-field-insert="insertField($event.detail.syntax)"
    data-route-message-template-picker
>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0 flex-1">
            <label for="{{ $fieldId }}" class="text-sm font-semibold text-slate-900">
                {{ $label }}
                @if($required)<span class="text-red-700" aria-hidden="true">*</span>@endif
            </label>
            <select
                id="{{ $fieldId }}"
                name="{{ $name }}"
                x-model="selected"
                @if($activeWhen)
                    x-bind:disabled="authoringState.{{ $activeWhen['field'] ?? '' }} !== '{{ $activeWhen['equals'] ?? '' }}'"
                    x-bind:required="authoringState.{{ $activeWhen['field'] ?? '' }} === '{{ $activeWhen['equals'] ?? '' }}'"
                @else
                    @required($required)
                @endif
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
            >
                <option value="">Choose a message template</option>
                @foreach($options as $option)
                    <option value="{{ $option['value'] }}" @selected((string) $value === (string) $option['value'])>{{ $option['label'] }}@if(filled($option['description'] ?? null)) — {{ $option['description'] }}@endif</option>
                @endforeach
                <template x-for="template in createdTemplates" :key="template.value">
                    <option x-bind:value="template.value" x-text="template.description ? `${template.label} — ${template.description}` : template.label"></option>
                </template>
            </select>
            @if(filled($field['help'] ?? null))
                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>
            @endif
        </div>

        <button
            type="button"
            x-on:click="openCreateMessage()"
            class="inline-flex w-full shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-100 sm:w-auto"
            data-route-message-template-create
        >
            + Create new message
        </button>
    </div>

    @error($name)
        <p class="text-sm text-red-700">{{ $message }}</p>
    @enderror

    <template x-teleport="body">
        <div
            x-cloak
            x-show="createOpen"
            x-transition.opacity
            x-on:keydown.escape.window="closeCreateMessage()"
            x-on:click.self="closeCreateMessage()"
            x-on:message-field-insert="insertField($event.detail.syntax)"
            data-route-message-authoring-id="{{ $fieldSuffix }}"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/60 p-4"
            role="presentation"
        >
            <section x-show="createOpen" x-transition class="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl ring-1 ring-black/10" role="dialog" aria-modal="true" aria-label="Create reusable Route message">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-700">Message Templates</p>
                        <h3 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Create new message</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Create reusable copy here and select it for this Route Point immediately.</p>
                    </div>
                    <button type="button" x-on:click="closeCreateMessage()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-600 hover:bg-slate-50" aria-label="Close"><span aria-hidden="true">×</span></button>
                </header>

                <div class="space-y-5 px-5 py-5 sm:px-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900">Channel</label>
                            <select x-model="channel" x-on:change="lastField = channel === 'sms' ? 'message' : 'body'" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm">
                                @foreach($availableChannels as $channel)
                                    <option value="{{ $channel }}">{{ \Illuminate\Support\Str::headline($channel) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900">Purpose</label>
                            <select x-model="purpose" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm">
                                @foreach($purposes as $purposeOption)
                                    <option value="{{ $purposeOption['value'] }}">{{ $purposeOption['label'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Use Marketing for nurture, check-ins, promotions, and other follow-up. Transactional is for service or operational messages.</p>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-900">Template name</label>
                        <input x-ref="templateName" x-model="templateName" type="text" maxlength="191" placeholder="Past Client Check-In" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm">
                    </div>

                    <template x-if="channel === 'email'">
                        <div class="space-y-4">
                            <div>
                                <label class="text-sm font-semibold text-slate-900">Subject</label>
                                <input x-model="subject" x-on:focus="lastField = 'subject'" data-template-authoring-field="subject" type="text" maxlength="255" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-900">Message</label>
                                <textarea x-model="body" x-on:focus="lastField = 'body'" data-template-authoring-field="body" rows="7" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm"></textarea>
                            </div>
                        </div>
                    </template>

                    <template x-if="channel === 'sms'">
                        <div>
                            <label class="text-sm font-semibold text-slate-900">Message</label>
                            <textarea x-model="message" x-on:focus="lastField = 'message'" data-template-authoring-field="message" rows="6" maxlength="1600" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm"></textarea>
                        </div>
                    </template>

                    <x-messaging.available-fields :groups="$availableFields" />

                    <div x-show="error" x-text="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"></div>

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                        <button type="button" x-on:click="closeCreateMessage()" x-bind:disabled="saving" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50 disabled:opacity-50 sm:w-auto">Cancel</button>
                        <button type="button" x-on:click="saveMessage()" x-bind:disabled="saving" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50 sm:w-auto"><span x-text="saving ? 'Creating…' : 'Create and select'"></span></button>
                    </div>
                </div>
            </section>
        </div>
    </template>
</div>