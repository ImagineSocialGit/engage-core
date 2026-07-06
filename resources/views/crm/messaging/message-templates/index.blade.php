<x-layouts.crm
    title="Message Templates"
    heading="Message Templates"
    subheading="Review and edit reusable message copy used by registrations, reminders, follow-ups, and campaign steps."
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Messaging
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">
                        Message Templates
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Choose a template to review where it is used, preview the copy, and safely edit message text without changing campaign, webinar, or route setup.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <span class="font-bold text-slate-950">{{ $presets->count() }}</span>
                    templates synced
                </div>
            </div>
        </section>

        @if($presets->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h2 class="text-xl font-extrabold tracking-tight text-slate-950">
                    No message templates are available yet.
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Run the preset sync to import the configured email and SMS templates before editing copy in the CRM.
                </p>
            </section>
        @else
            <div class="grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">
                            Templates
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Select the message copy you want to review or edit.
                        </p>
                    </div>

                    <div class="max-h-[42rem] divide-y divide-slate-100 overflow-y-auto">
                        @foreach($groupedPresets as $group => $groupPresets)
                            <div class="bg-slate-50 px-5 py-2 text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">
                                {{ str_replace(':', ' · ', $group) }}
                            </div>

                            @foreach($groupPresets as $preset)
                                <a
                                    href="{{ route('crm.messaging.message-templates.index', ['preset' => $preset->getKey()]) }}"
                                    class="block px-5 py-4 transition hover:bg-slate-50 {{ $selectedPreset && $selectedPreset->is($preset) ? 'bg-indigo-50/70' : '' }}"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="font-bold text-slate-950">
                                                {{ $preset->name }}
                                            </div>
                                            <div class="mt-1 text-sm text-slate-500">
                                                {{ $preset->message_type ? str_replace('_', ' ', $preset->message_type) : 'General message' }}
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                                {{ $preset->channel }}
                                            </span>

                                            @if($preset->is_customized)
                                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">
                                                    Customized
                                                </span>
                                            @else
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                                    Synced
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        @endforeach
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    @if($selectedPreset)
                        <div class="border-b border-slate-200 px-6 py-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                                        Selected template
                                    </p>
                                    <h2 class="mt-2 text-xl font-extrabold tracking-tight text-slate-950">
                                        {{ $selectedPreset->name }}
                                    </h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ $selectedPreset->description ?: 'Reusable copy for this message context.' }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                        {{ $selectedPreset->channel }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                        {{ str_replace('_', ' ', $selectedPreset->purpose) }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                        {{ str_replace('_', ' ', $selectedPreset->scope) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                            <form
                                method="POST"
                                action="{{ route('crm.messaging.message-templates.update', $selectedPreset) }}"
                                class="space-y-5"
                            >
                                @csrf
                                @method('PATCH')

                                <div>
                                    <label for="name" class="mb-2 block text-sm font-bold text-slate-900">
                                        Template name
                                    </label>
                                    <input
                                        id="name"
                                        name="name"
                                        value="{{ old('name', $selectedPreset->name) }}"
                                        class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                    @error('name')
                                        <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="description" class="mb-2 block text-sm font-bold text-slate-900">
                                        Helper description
                                    </label>
                                    <textarea
                                        id="description"
                                        name="description"
                                        rows="2"
                                        class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >{{ old('description', $selectedPreset->description) }}</textarea>
                                    @error('description')
                                        <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                @if($selectedPreset->payload_class === \App\Modules\Messaging\Payloads\EmailPayload::class)
                                    <div>
                                        <label for="subject" class="mb-2 block text-sm font-bold text-slate-900">
                                            Subject
                                        </label>
                                        <input
                                            id="subject"
                                            name="payload[subject]"
                                            value="{{ old('payload.subject', $editablePayload['subject'] ?? '') }}"
                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >
                                        @error('payload.subject')
                                            <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="body" class="mb-2 block text-sm font-bold text-slate-900">
                                            Body
                                        </label>
                                        <textarea
                                            id="body"
                                            name="payload[body]"
                                            rows="8"
                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >{{ old('payload.body', $editablePayload['body'] ?? '') }}</textarea>
                                        @error('payload.body')
                                            <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label for="cta_label" class="mb-2 block text-sm font-bold text-slate-900">
                                                Button label
                                            </label>
                                            <input
                                                id="cta_label"
                                                name="payload[cta][label]"
                                                value="{{ old('payload.cta.label', data_get($editablePayload, 'cta.label', '')) }}"
                                                class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            >
                                        </div>
                                        <div>
                                            <label for="cta_url" class="mb-2 block text-sm font-bold text-slate-900">
                                                Button link
                                            </label>
                                            <input
                                                id="cta_url"
                                                name="payload[cta][url]"
                                                value="{{ old('payload.cta.url', data_get($editablePayload, 'cta.url', '')) }}"
                                                class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            >
                                        </div>
                                    </div>

                                    <div>
                                        <label for="footer" class="mb-2 block text-sm font-bold text-slate-900">
                                            Footer
                                        </label>
                                        <textarea
                                            id="footer"
                                            name="payload[footer]"
                                            rows="2"
                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >{{ old('payload.footer', $editablePayload['footer'] ?? '') }}</textarea>
                                    </div>
                                @elseif($selectedPreset->payload_class === \App\Modules\Messaging\Payloads\SmsPayload::class)
                                    <div>
                                        <label for="message" class="mb-2 block text-sm font-bold text-slate-900">
                                            Text message
                                        </label>
                                        <textarea
                                            id="message"
                                            name="payload[message]"
                                            rows="6"
                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >{{ old('payload.message', $editablePayload['message'] ?? '') }}</textarea>
                                        @error('payload.message')
                                            <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @else
                                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                        This payload type does not have a safe editor yet.
                                    </div>
                                @endif

                                <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-5">
                                    <p class="text-sm text-slate-500">
                                        Saving marks this template as customized so normal sync will not overwrite it.
                                    </p>
                                    <button
                                        type="submit"
                                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white transition hover:bg-slate-800"
                                    >
                                        Save template
                                    </button>
                                </div>
                            </form>

                            <aside class="space-y-4">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <h3 class="text-sm font-extrabold text-slate-950">
                                        Used by
                                    </h3>
                                    <dl class="mt-3 space-y-2 text-sm">
                                        <div>
                                            <dt class="text-slate-500">Message</dt>
                                            <dd class="font-semibold text-slate-900">{{ str_replace('_', ' ', $selectedPreset->message_type ?? 'General') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Selected contexts</dt>
                                            <dd class="font-semibold text-slate-900">{{ $selectedPreset->active_assignments_count }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Timing</dt>
                                            <dd class="font-semibold text-slate-900">{{ str_replace('_', ' ', $selectedPreset->timing) }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-extrabold text-slate-950">
                                        Selected for workflows
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Choose which compatible template this workflow should use. Timing, triggers, and skip rules stay in the workflow or campaign setup.
                                    </p>

                                    @if($selectedPreset->assignments->isEmpty())
                                        <p class="mt-3 text-sm text-slate-500">
                                            This template is not currently selected for any workflow.
                                        </p>
                                    @else
                                        <div class="mt-4 space-y-4">
                                            @foreach($selectedPreset->assignments as $assignment)
                                                @php
                                                    $options = $assignmentOptions->get($assignment->getKey(), collect());
                                                    $contextLabel = $assignment->campaign_key
                                                        ? 'Campaign step '.($assignment->campaign_step ?? '?')
                                                        : 'Message workflow';
                                                @endphp

                                                <form
                                                    method="POST"
                                                    action="{{ route('crm.messaging.message-templates.assignments.update', $assignment) }}"
                                                    class="rounded-2xl border border-slate-200 bg-slate-50 p-3"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <div class="text-sm font-bold text-slate-950">
                                                        {{ $contextLabel }}
                                                    </div>
                                                    <div class="mt-1 text-xs text-slate-500">
                                                        {{ str_replace('_', ' ', $assignment->surface ?: $assignment->scope) }}
                                                        @if($assignment->message_type)
                                                            · {{ str_replace('_', ' ', $assignment->message_type) }}
                                                        @endif
                                                    </div>

                                                    <label for="assignment_{{ $assignment->getKey() }}" class="mt-3 block text-xs font-extrabold uppercase tracking-wide text-slate-500">
                                                        Active template
                                                    </label>
                                                    <select
                                                        id="assignment_{{ $assignment->getKey() }}"
                                                        name="message_template_preset_id"
                                                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                                    >
                                                        @foreach($options as $option)
                                                            <option value="{{ $option->getKey() }}" @selected((int) $assignment->message_template_preset_id === (int) $option->getKey())>
                                                                {{ $option->name }}{{ $option->is_customized ? ' — customized' : '' }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('message_template_preset_id')
                                                        <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                                                    @enderror

                                                    <button
                                                        type="submit"
                                                        class="mt-3 inline-flex min-h-10 items-center justify-center rounded-full bg-white px-4 text-xs font-extrabold text-slate-950 ring-1 ring-slate-300 transition hover:bg-slate-100"
                                                    >
                                                        Use selected template
                                                    </button>
                                                </form>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-extrabold text-slate-950">
                                        Tokens used
                                    </h3>
                                    @if($tokens === [])
                                        <p class="mt-2 text-sm text-slate-500">
                                            This template does not use any dynamic tokens.
                                        </p>
                                    @else
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach($tokens as $token)
                                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                                    { {{ $token }} }
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <details class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <summary class="cursor-pointer text-sm font-extrabold text-slate-950">
                                        Details
                                    </summary>
                                    <dl class="mt-3 space-y-2 wrap-break-word text-xs text-slate-600">
                                        <div>
                                            <dt class="font-bold text-slate-900">Template key</dt>
                                            <dd>{{ $selectedPreset->key }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-bold text-slate-900">Source</dt>
                                            <dd>{{ $selectedPreset->source_config_path ?: 'Database template' }}</dd>
                                        </div>
                                    </dl>
                                </details>
                            </aside>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>
