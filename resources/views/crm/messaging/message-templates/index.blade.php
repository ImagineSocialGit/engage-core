
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
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Messaging
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">
                        Message Templates
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Review reusable message copy in one place. Campaigns, webinars, and automatic follow-ups decide when messages send; this page only edits what those messages say.
                    </p>
                </div>

                <div class="space-y-3 lg:min-w-80">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            <span class="font-bold text-slate-950">{{ $presets->count() }}</span>
                            templates synced
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            <span class="font-bold text-slate-950">{{ $catalogGroups->count() }}</span>
                            template groups
                        </div>
                    </div>

                    @if(function_exists('module_enabled') && module_enabled('webinars') && \Illuminate\Support\Facades\Route::has('crm.webinars.message-templates.index'))
                        <a
                            href="{{ route('crm.webinars.message-templates.index') }}"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                        >
                            Choose webinar templates
                        </a>
                    @endif

                    @if(function_exists('module_enabled') && module_enabled('campaigns') && \Illuminate\Support\Facades\Route::has('crm.campaigns.message-templates.index'))
                        <a
                            href="{{ route('crm.campaigns.message-templates.index') }}"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                        >
                            Choose campaign templates
                        </a>
                    @endif
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
            <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.82fr)_minmax(0,1.18fr)]">
                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">
                            Template library
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Filter by channel, purpose, and area. Selecting a group shows all related messages together.
                        </p>
                    </div>

                    <form method="GET" action="{{ route('crm.messaging.message-templates.index') }}" class="border-b border-slate-200 bg-slate-50 p-5">
                        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3">
                            <div>
                                <label for="channel" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">
                                    Channel
                                </label>
                                <select
                                    id="channel"
                                    name="channel"
                                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">All channels</option>
                                    @foreach($filterOptions['channels'] as $option)
                                        <option value="{{ $option['value'] }}" @selected($filters['channel'] === $option['value'])>
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="purpose" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">
                                    Purpose
                                </label>
                                <select
                                    id="purpose"
                                    name="purpose"
                                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">All purposes</option>
                                    @foreach($filterOptions['purposes'] as $option)
                                        <option value="{{ $option['value'] }}" @selected($filters['purpose'] === $option['value'])>
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="module" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">
                                    Area
                                </label>
                                <select
                                    id="module"
                                    name="module"
                                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">All areas</option>
                                    @foreach($filterOptions['modules'] as $option)
                                        <option value="{{ $option['value'] }}" @selected($filters['module'] === $option['value'])>
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button
                                type="submit"
                                class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white transition hover:bg-slate-800"
                            >
                                Filter templates
                            </button>
                            @if($filters['channel'] || $filters['purpose'] || $filters['module'])
                                <a
                                    href="{{ route('crm.messaging.message-templates.index') }}"
                                    class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Clear filters
                                </a>
                            @endif
                        </div>
                    </form>

                    <div class="max-h-[42rem] divide-y divide-slate-100 overflow-y-auto">
                        @forelse($catalogGroups as $group)
                            @php
                                $firstEntry = $group['entries']->first();
                                $firstPreset = $firstEntry?->messageTemplatePreset;
                                $groupUrl = route('crm.messaging.message-templates.index', array_filter([
                                    'channel' => $filters['channel'],
                                    'purpose' => $filters['purpose'],
                                    'module' => $filters['module'],
                                    'group' => $group['key'],
                                    'preset' => $firstPreset?->getKey(),
                                ], static fn ($value) => $value !== null && $value !== ''));
                                $selected = $selectedGroup && $selectedGroup['key'] === $group['key'];
                            @endphp

                            <a
                                href="{{ $groupUrl }}"
                                class="block px-5 py-4 transition hover:bg-slate-50 {{ $selected ? 'bg-indigo-50/70' : '' }}"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">
                                            {{ $group['module_label'] }} · {{ str_replace('_', ' ', $group['purpose']) }} · {{ strtoupper($group['channel']) }}
                                        </div>
                                        <div class="mt-1 font-extrabold text-slate-950">
                                            {{ $group['label'] }}
                                        </div>
                                        <div class="mt-1 text-sm text-slate-500">
                                            {{ $group['entries']->count() }} {{ Str::plural('message', $group['entries']->count()) }} in this group
                                        </div>
                                    </div>

                                    <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                        {{ $group['entries']->count() }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="p-6 text-sm text-slate-600">
                                No templates match those filters.
                            </div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    @if($selectedGroup && $selectedPreset)
                        @php
                            $selectedCatalogEntry = $selectedPreset->catalogEntries->firstWhere('group_key', $selectedGroup['key'])
                                ?: $selectedPreset->catalogEntries->first();
                            $groupQuery = array_filter([
                                'channel' => $filters['channel'],
                                'purpose' => $filters['purpose'],
                                'module' => $filters['module'],
                                'group' => $selectedGroup['key'],
                            ], static fn ($value) => $value !== null && $value !== '');
                        @endphp

                        <div class="border-b border-slate-200 px-6 py-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                                        Selected group
                                    </p>
                                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">
                                        {{ $selectedGroup['label'] }}
                                    </h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        Browse every related message in this group, then edit the copy for the selected message below.
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                        {{ $selectedGroup['channel'] }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                        {{ str_replace('_', ' ', $selectedGroup['purpose']) }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                        {{ $selectedGroup['module_label'] }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                            <h3 class="text-sm font-extrabold text-slate-950">
                                Messages in this group
                            </h3>
                            <div class="mt-3 flex gap-2 overflow-x-auto pb-4">
                                @foreach($selectedGroupEntries as $entry)
                                    @php
                                        $entryPreset = $entry->messageTemplatePreset;
                                    @endphp
                                    @if($entryPreset)
                                        <a
                                            href="{{ route('crm.messaging.message-templates.index', $groupQuery + ['preset' => $entryPreset->getKey()]) }}"
                                            class="inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-extrabold transition {{ $selectedPreset->is($entryPreset) ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}"
                                        >
                                            <span>{{ $entry->item_label }}</span>
                                            @if($entryPreset->is_customized)
                                                <span class="rounded-full bg-white/20 px-2 py-0.5 text-[0.65rem] uppercase tracking-wide {{ $selectedPreset->is($entryPreset) ? 'text-white' : 'bg-amber-100 text-amber-800' }}">
                                                    Custom
                                                </span>
                                            @endif
                                        </a>
                                    @endif
                                @endforeach
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

                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">
                                        Editing message
                                    </p>
                                    <h3 class="mt-1 text-lg font-extrabold text-slate-950">
                                        {{ $selectedCatalogEntry?->item_label ?: str_replace('_', ' ', $selectedPreset->message_type ?? 'Message') }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $selectedPreset->name }}
                                    </p>
                                </div>

                                <div>
                                    <label for="name" class="mb-2 block text-sm font-bold text-slate-900">
                                        Template title
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
                                        Template info
                                    </h3>
                                    <dl class="mt-3 space-y-2 text-sm">
                                        <div>
                                            <dt class="text-slate-500">Group</dt>
                                            <dd class="font-semibold text-slate-900">{{ $selectedGroup['label'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Message</dt>
                                            <dd class="font-semibold text-slate-900">{{ $selectedCatalogEntry?->item_label ?: str_replace('_', ' ', $selectedPreset->message_type ?? 'General') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Selected contexts</dt>
                                            <dd class="font-semibold text-slate-900">{{ $selectedPreset->active_assignments_count }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-extrabold text-slate-950">
                                        Used by
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        This shows where the template is currently selected. Change template selection from the campaign, webinar, or automatic follow-up setup screen.
                                    </p>

                                    @if($usageSummaries->isEmpty())
                                        <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                                            This template is available, but nothing is currently using it.
                                        </div>
                                    @else
                                        <div class="mt-4 space-y-3">
                                            @foreach($usageSummaries as $usage)
                                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                    <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">
                                                        {{ $usage['module_label'] }}
                                                    </div>
                                                    <div class="mt-1 text-sm font-bold text-slate-950">
                                                        {{ $usage['context_label'] }}
                                                    </div>
                                                    <div class="mt-1 text-sm text-slate-600">
                                                        {{ $usage['item_label'] }}
                                                    </div>
                                                    @if($usage['detail'])
                                                        <div class="mt-1 text-xs text-slate-500">
                                                            {{ $usage['detail'] }}
                                                        </div>
                                                    @endif

                                                    @if($usage['url'])
                                                        <a
                                                            href="{{ $usage['url'] }}"
                                                            class="mt-3 inline-flex min-h-8 items-center justify-center rounded-full border border-slate-300 bg-white px-3 text-xs font-extrabold text-slate-700 transition hover:bg-slate-50"
                                                        >
                                                            Manage selection
                                                        </a>
                                                    @endif
                                                </div>
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
                                    <dl class="mt-3 space-y-2 break-words text-xs text-slate-600">
                                        <div>
                                            <dt class="font-bold text-slate-900">Template key</dt>
                                            <dd>{{ $selectedPreset->key }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-bold text-slate-900">Catalog entry</dt>
                                            <dd>{{ $selectedCatalogEntry?->item_key ?: 'Uncataloged' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-bold text-slate-900">Source</dt>
                                            <dd>{{ $selectedPreset->source_config_path ?: 'Database template' }}</dd>
                                        </div>
                                    </dl>
                                </details>
                            </aside>
                        </div>
                    @else
                        <div class="p-8 text-center">
                            <h2 class="text-xl font-extrabold tracking-tight text-slate-950">
                                No template group selected.
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Adjust the filters or clear them to choose a message template group.
                            </p>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>


