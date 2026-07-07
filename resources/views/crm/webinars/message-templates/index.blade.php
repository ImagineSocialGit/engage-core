<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose which reusable Messaging templates Webinars uses for confirmations, reminders, waitlist alerts, and replay follow-ups."
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Webinars
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">
                        Message selection
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Webinars decide when lifecycle messages are scheduled. Messaging owns the reusable copy. Use this page to choose the active template for each webinar-owned message context.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a
                        href="{{ route('crm.messaging.message-templates.index', ['module' => 'webinars']) }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                    >
                        Edit message copy
                    </a>

                    <a
                        href="{{ route('crm.webinar-series.index') }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white transition hover:bg-slate-800"
                    >
                        View webinars
                    </a>
                </div>
            </div>
        </section>

        @if($sections->every(fn ($section) => $section['entries']->isEmpty()))
            <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h2 class="text-xl font-extrabold tracking-tight text-slate-950">
                    No webinar message templates are available yet.
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Run the Messaging template preset sync after confirming the webinar message config exists.
                </p>
            </section>
        @else
            <div class="grid gap-6 xl:grid-cols-[minmax(16rem,0.45fr)_minmax(0,1fr)]">
                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">
                            Message areas
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Choose an area to review its active templates.
                        </p>
                    </div>

                    <div class="divide-y divide-slate-100">
                        @foreach($sections as $section)
                            <a
                                href="{{ route('crm.webinars.message-templates.index', ['section' => $section['key']]) }}"
                                class="block px-5 py-4 transition hover:bg-slate-50 {{ $selectedSectionKey === $section['key'] ? 'bg-stone-50/90' : '' }}"
                            >
                                <div class="font-extrabold text-slate-950">
                                    {{ $section['label'] }}
                                </div>
                                <div class="mt-1 text-sm leading-5 text-slate-500">
                                    {{ $section['description'] }}
                                </div>
                                <div class="mt-3 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                    {{ $section['entries']->count() }} {{ Str::plural('message', $section['entries']->count()) }}
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    @php
                        $selectedSection = $sections->get($selectedSectionKey) ?? $sections->first();
                    @endphp

                    @if($selectedSection)
                        <div class="border-b border-slate-200 px-6 py-5">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                                Selected area
                            </p>
                            <h2 class="mt-2 text-xl font-extrabold tracking-tight text-slate-950">
                                {{ $selectedSection['label'] }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ $selectedSection['description'] }}
                            </p>
                        </div>

                        @if($selectedSection['entries']->isEmpty())
                            <div class="p-6">
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                                    No synced Messaging template is available for this webinar message area yet.
                                </div>
                            </div>
                        @else
                            <div class="divide-y divide-slate-100">
                                @foreach($selectedSection['entries'] as $entryState)
                                    @php
                                        $entry = $entryState['catalog_entry'];
                                        $selectedPreset = $entryState['selected_preset'];
                                        $selectedCatalogEntry = $selectedPreset?->catalogEntries?->firstWhere('usage_type', $entry->usage_type)
                                            ?: $selectedPreset?->catalogEntries?->first();
                                        $options = $entryState['options'];
                                    @endphp

                                    <div id="message-{{ $entry->id }}" class="p-6">
                                        <div class="grid gap-5 lg:grid-cols-[minmax(0,0.72fr)_minmax(20rem,1fr)] lg:items-start">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h3 class="text-base font-extrabold text-slate-950">
                                                        {{ $entry->item_label }}
                                                    </h3>
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                                        {{ $entry->channel === 'sms' ? 'SMS' : Str::headline($entry->channel) }}
                                                    </span>
                                                </div>

                                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Timing</dt>
                                                        <dd class="mt-1 font-semibold text-slate-900">
                                                            {{ $entryState['schedule_label'] ?: Str::headline($selectedPreset?->timing ?? 'configured') }}
                                                        </dd>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Purpose</dt>
                                                        <dd class="mt-1 font-semibold text-slate-900">
                                                            {{ Str::headline(str_replace('_', ' ', $entry->purpose)) }} · {{ Str::headline(str_replace('_', ' ', $entry->scope)) }}
                                                        </dd>
                                                    </div>
                                                </dl>

                                                <details class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                                                    <summary class="cursor-pointer font-extrabold text-slate-800">Details</summary>
                                                    <dl class="mt-3 space-y-2 break-words">
                                                        <div>
                                                            <dt class="font-bold text-slate-900">Message context</dt>
                                                            <dd>{{ $entryState['message_type'] }}</dd>
                                                        </div>
                                                        <div>
                                                            <dt class="font-bold text-slate-900">Selected template source</dt>
                                                            <dd>{{ $selectedPreset?->source_config_path ?: 'Database template' }}</dd>
                                                        </div>
                                                    </dl>
                                                </details>
                                            </div>

                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <h4 class="text-sm font-extrabold text-slate-950">
                                                            Active template
                                                        </h4>
                                                        <p class="mt-1 text-sm text-slate-500">
                                                            {{ $selectedPreset?->name ?: 'No template selected for this message.' }}
                                                        </p>
                                                    </div>

                                                    @if($selectedPreset)
                                                        <a
                                                            href="{{ route('crm.messaging.message-templates.index', ['channel' => $selectedPreset->channel, 'purpose' => $selectedPreset->purpose, 'module' => $selectedCatalogEntry?->module_key, 'group' => $selectedCatalogEntry?->group_key, 'preset' => $selectedPreset->getKey()]) }}"
                                                            class="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-300 px-4 text-xs font-extrabold text-slate-700 transition hover:bg-slate-50"
                                                        >
                                                            Edit copy
                                                        </a>
                                                    @endif
                                                </div>

                                                @if($options->isEmpty())
                                                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                                        No compatible Messaging template is cataloged for this webinar message.
                                                    </div>
                                                @else
                                                    <form
                                                        method="POST"
                                                        action="{{ route('crm.webinars.message-templates.update') }}"
                                                        class="mt-4 space-y-3"
                                                    >
                                                        @csrf
                                                        @method('PATCH')

                                                        <input type="hidden" name="context_key" value="{{ $entryState['context_key'] }}">
                                                        <input type="hidden" name="catalog_entry_id" value="{{ $entry->id }}">
                                                        <input type="hidden" name="channel" value="{{ $entry->channel }}">
                                                        <input type="hidden" name="purpose" value="{{ $entry->purpose }}">
                                                        <input type="hidden" name="scope" value="{{ $entry->scope }}">
                                                        <input type="hidden" name="surface" value="{{ $entryState['surface'] }}">
                                                        <input type="hidden" name="message_type" value="{{ $entryState['message_type'] }}">

                                                        <label for="message_template_preset_id_{{ $entry->id }}" class="block text-sm font-bold text-slate-900">
                                                            Template for this message
                                                        </label>
                                                        <select
                                                            id="message_template_preset_id_{{ $entry->id }}"
                                                            name="message_template_preset_id"
                                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-500"
                                                        >
                                                            @foreach($options as $option)
                                                                @php($optionPreset = $option->messageTemplatePreset)
                                                                @if($optionPreset)
                                                                    <option
                                                                        value="{{ $optionPreset->getKey() }}"
                                                                        @selected($selectedPreset && $selectedPreset->is($optionPreset))
                                                                    >
                                                                        {{ $option->item_label }} — {{ $optionPreset->name }}
                                                                    </option>
                                                                @endif
                                                            @endforeach
                                                        </select>

                                                        @error('message_template_preset_id')
                                                            <p class="text-sm font-semibold text-red-600">{{ $message }}</p>
                                                        @enderror

                                                        <div class="flex items-center justify-between gap-4 pt-1">
                                                            <p class="text-xs leading-5 text-slate-500">
                                                                This changes future webinar message selection only. It does not edit copy or reschedule messages already created.
                                                            </p>
                                                            <button
                                                                type="submit"
                                                                class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white transition hover:bg-slate-800"
                                                            >
                                                                Save selection
                                                            </button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>
