<x-layouts.crm
    title="Message Templates"
    heading="Message Templates"
    subheading="Edit the meaningful difference, understand shared content, and preview the exact published message."
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">Messaging</p>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">Message Templates</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Campaigns, webinars, and automations decide what sends and when. Messaging owns what the message says. Shared changes are resolved and published into immutable message versions before runtime delivery.
                    </p>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 lg:min-w-80">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <span class="font-bold text-slate-950">{{ $presets->count() }}</span> messages
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <span class="font-bold text-slate-950">{{ $catalogGroups->count() }}</span> business families
                    </div>
                </div>
            </div>
        </section>

        @if($presets->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h2 class="text-xl font-extrabold tracking-tight text-slate-950">No message templates are available yet.</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Run preset synchronization before editing message content.</p>
            </section>
        @else
            <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.82fr)_minmax(0,1.18fr)] xl:items-start">
                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">Template library</h2>
                        <p class="mt-1 text-sm text-slate-500">Choose the business family first, then the timing, outcome, or channel member.</p>
                    </div>

                    <form method="GET" action="{{ route('crm.messaging.message-templates.index') }}" class="border-b border-slate-200 bg-slate-50 p-4 sm:p-5">
                        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3">
                            @foreach([
                                ['key' => 'channel', 'label' => 'Channel', 'options' => $filterOptions['channels'], 'all' => 'All channels'],
                                ['key' => 'purpose', 'label' => 'Purpose', 'options' => $filterOptions['purposes'], 'all' => 'All purposes'],
                                ['key' => 'module', 'label' => 'Area', 'options' => $filterOptions['modules'], 'all' => 'All areas'],
                            ] as $filter)
                                <div>
                                    <label for="{{ $filter['key'] }}" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $filter['label'] }}</label>
                                    <select id="{{ $filter['key'] }}" name="{{ $filter['key'] }}" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm">
                                        <option value="">{{ $filter['all'] }}</option>
                                        @foreach($filter['options'] as $option)
                                            <option value="{{ $option['value'] }}" @selected($filters[$filter['key']] === $option['value'])>{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white">Filter templates</button>
                            @if($filters['channel'] || $filters['purpose'] || $filters['module'])
                                <a href="{{ route('crm.messaging.message-templates.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700">Clear filters</a>
                            @endif
                        </div>
                    </form>

                    <div class="divide-y divide-slate-100">
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
                                ]));
                                $selected = ($selectedGroup['key'] ?? null) === $group['key'];
                            @endphp
                            <a href="{{ $groupUrl }}" class="block px-4 py-4 transition hover:bg-slate-50 sm:px-5 {{ $selected ? 'bg-indigo-50/70' : '' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $group['module_label'] }} · {{ strtoupper($group['channel']) }}</div>
                                        <div class="mt-1 break-words text-sm font-extrabold text-slate-950">{{ $group['label'] }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $group['entries']->count() }} {{ \Illuminate\Support\Str::plural('member', $group['entries']->count()) }}</div>
                                    </div>
                                    @if($selected)<span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800">Selected</span>@endif
                                </div>
                            </a>
                        @empty
                            <div class="p-6 text-center text-sm text-slate-500">No families match these filters.</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    @if($selectedPreset && $selectedGroup)
                        <div class="border-b border-slate-200 p-4 sm:p-6">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $selectedGroup['module_label'] }} · {{ strtoupper($selectedPreset->channel) }}</p>
                                    <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-950">{{ $selectedGroup['label'] }}</h2>
                                    <p class="mt-2 text-sm text-slate-600">Choose a member below. Shared content and message-specific differences are shown separately.</p>
                                </div>
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    <div class="font-extrabold">Published{{ $currentTemplateVersion ? ' · v'.$currentTemplateVersion->version : '' }}</div>
                                    <div class="mt-1 text-xs leading-5">Editing content does not activate a Campaign, Webinar, or Route and does not rewrite already-pinned historical message versions.</div>
                                </div>
                            </div>

                            <div class="mt-5 flex gap-2 overflow-x-auto pb-1">
                                @foreach($selectedGroupEntries as $entry)
                                    @php
                                        $entryPreset = $entry->messageTemplatePreset;
                                        $entryUrl = route('crm.messaging.message-templates.index', array_filter([
                                            'channel' => $filters['channel'],
                                            'purpose' => $filters['purpose'],
                                            'module' => $filters['module'],
                                            'group' => $selectedGroup['key'],
                                            'preset' => $entryPreset?->getKey(),
                                        ]));
                                    @endphp
                                    <a href="{{ $entryUrl }}" class="shrink-0 rounded-full border px-4 py-2 text-sm font-extrabold {{ $entryPreset?->is($selectedPreset) ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-700' }}">
                                        {{ $entry->item_label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        <div class="space-y-6 p-4 sm:p-6">
                            @if($sharedCompositionLayers->isNotEmpty())
                                <section class="space-y-4">
                                    <div>
                                        <h3 class="text-lg font-extrabold text-slate-950">Shared content</h3>
                                        <p class="mt-1 text-sm text-slate-600">Edit these values once. Publishing a shared change republishes only messages that currently inherit the changed layer.</p>
                                    </div>

                                    @foreach($sharedCompositionLayers as $shared)
                                        @php $layer = $shared['layer']; $layerPayload = is_array($layer->payload) ? $layer->payload : []; @endphp
                                        <details class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-4" @if($loop->count === 1) open @endif>
                                            <summary class="cursor-pointer list-none">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <div class="font-extrabold text-slate-950">{{ $shared['label'] }}</div>
                                                        <div class="mt-1 text-xs text-slate-600">{{ implode(', ', $shared['field_labels']) }}</div>
                                                    </div>
                                                    <div class="text-xs font-bold text-indigo-900">Used by {{ $shared['affected_count'] }} {{ \Illuminate\Support\Str::plural('message', $shared['affected_count']) }}</div>
                                                </div>
                                            </summary>

                                            <form method="POST" action="{{ route('crm.messaging.message-templates.composition-layers.update', $layer) }}" class="mt-5 space-y-4">
                                                @csrf
                                                @method('PATCH')

                                                @foreach($layerPayload as $field => $value)
                                                    @if(in_array($field, ['subject', 'footer']))
                                                        <div>
                                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">{{ \Illuminate\Support\Str::headline($field) }}</label>
                                                            <input name="payload[{{ $field }}]" value="{{ old('payload.'.$field, $value) }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm">
                                                        </div>
                                                    @elseif($field === 'body' || $field === 'message')
                                                        <div>
                                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">{{ \Illuminate\Support\Str::headline($field) }}</label>
                                                            <textarea name="payload[{{ $field }}]" rows="5" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm">{{ old('payload.'.$field, $value) }}</textarea>
                                                        </div>
                                                    @elseif(in_array($field, ['cta', 'secondary_link']) && is_array($value))
                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            <div><label class="mb-1.5 block text-sm font-extrabold text-slate-800">{{ $field === 'cta' ? 'CTA' : 'Secondary link' }} label</label><input name="payload[{{ $field }}][label]" value="{{ old('payload.'.$field.'.label', $value['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm"></div>
                                                            <div><label class="mb-1.5 block text-sm font-extrabold text-slate-800">URL</label><input name="payload[{{ $field }}][url]" value="{{ old('payload.'.$field.'.url', $value['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm"></div>
                                                        </div>
                                                    @elseif($field === 'ctas' && is_array($value))
                                                        <div class="space-y-3">
                                                            <div class="text-sm font-extrabold text-slate-800">CTA set</div>
                                                            @foreach($value as $index => $cta)
                                                                <div class="grid gap-3 sm:grid-cols-2">
                                                                    <input aria-label="CTA {{ $index + 1 }} label" name="payload[ctas][{{ $index }}][label]" value="{{ old('payload.ctas.'.$index.'.label', $cta['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm">
                                                                    <input aria-label="CTA {{ $index + 1 }} URL" name="payload[ctas][{{ $index }}][url]" value="{{ old('payload.ctas.'.$index.'.url', $cta['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm">
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                @endforeach

                                                <div class="rounded-xl border border-indigo-200 bg-white px-4 py-3 text-sm text-slate-700">
                                                    <strong>Impact review:</strong> this shared layer currently contributes to {{ $shared['affected_count'] }} {{ \Illuminate\Support\Str::plural('message', $shared['affected_count']) }}. Publishing creates new immutable versions only where the resolved payload changes.
                                                </div>
                                                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-indigo-700 px-5 text-sm font-extrabold text-white">Publish shared change</button>
                                            </form>
                                        </details>
                                    @endforeach
                                </section>
                            @endif

                            <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.38fr)]">
                                <div class="space-y-5">
                                    <div>
                                        <h3 class="text-lg font-extrabold text-slate-950">Override this message</h3>
                                        <p class="mt-1 text-sm text-slate-600">Only fields that differ from the inherited/source baseline are stored as a message override. Matching the baseline again clears that field override automatically.</p>
                                    </div>

                                    <form method="POST" action="{{ route('crm.messaging.message-templates.update', $selectedPreset) }}" class="space-y-5">
                                        @csrf
                                        @method('PATCH')

                                        @if($selectedPreset->payload_class === \App\Modules\Messaging\Payloads\EmailPayload::class)
                                            @foreach(['subject' => 'Subject', 'body' => 'Body', 'footer' => 'Footer'] as $field => $label)
                                                <div>
                                                    <div class="mb-1.5 flex items-center justify-between gap-3">
                                                        <label class="text-sm font-extrabold text-slate-800">{{ $label }}</label>
                                                        @if(isset($fieldSources[$field]))<span class="text-xs font-bold text-slate-500">{{ $fieldSources[$field]['label'] }}</span>@endif
                                                    </div>
                                                    @if($field === 'body' || $field === 'footer')
                                                        <textarea name="payload[{{ $field }}]" rows="{{ $field === 'body' ? 12 : 4 }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm">{{ old('payload.'.$field, $editablePayload[$field] ?? '') }}</textarea>
                                                    @else
                                                        <input name="payload[{{ $field }}]" value="{{ old('payload.'.$field, $editablePayload[$field] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm">
                                                    @endif
                                                    @error('payload.'.$field)<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                                </div>
                                            @endforeach

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <div><div class="mb-1.5 flex items-center justify-between"><label class="text-sm font-extrabold text-slate-800">Primary CTA label</label>@if(isset($fieldSources['cta']))<span class="text-xs font-bold text-slate-500">{{ $fieldSources['cta']['label'] }}</span>@endif</div><input name="payload[cta][label]" value="{{ old('payload.cta.label', $editablePayload['cta']['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm"></div>
                                                <div><label class="mb-1.5 block text-sm font-extrabold text-slate-800">Primary CTA URL</label><input name="payload[cta][url]" value="{{ old('payload.cta.url', $editablePayload['cta']['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm"></div>
                                            </div>

                                            @if(($editablePayload['ctas'] ?? []) !== [])
                                                <div class="space-y-3">
                                                    <div class="flex items-center justify-between"><div class="text-sm font-extrabold text-slate-800">CTA set</div>@if(isset($fieldSources['ctas']))<span class="text-xs font-bold text-slate-500">{{ $fieldSources['ctas']['label'] }}</span>@endif</div>
                                                    @foreach($editablePayload['ctas'] as $index => $cta)
                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            <input aria-label="CTA {{ $index + 1 }} label" name="payload[ctas][{{ $index }}][label]" value="{{ old('payload.ctas.'.$index.'.label', $cta['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                                                            <input aria-label="CTA {{ $index + 1 }} URL" name="payload[ctas][{{ $index }}][url]" value="{{ old('payload.ctas.'.$index.'.url', $cta['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <div><div class="mb-1.5 flex items-center justify-between"><label class="text-sm font-extrabold text-slate-800">Secondary link label</label>@if(isset($fieldSources['secondary_link']))<span class="text-xs font-bold text-slate-500">{{ $fieldSources['secondary_link']['label'] }}</span>@endif</div><input name="payload[secondary_link][label]" value="{{ old('payload.secondary_link.label', $editablePayload['secondary_link']['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm"></div>
                                                <div><label class="mb-1.5 block text-sm font-extrabold text-slate-800">Secondary link URL</label><input name="payload[secondary_link][url]" value="{{ old('payload.secondary_link.url', $editablePayload['secondary_link']['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm"></div>
                                            </div>
                                        @elseif($selectedPreset->payload_class === \App\Modules\Messaging\Payloads\SmsPayload::class)
                                            <div>
                                                <div class="mb-1.5 flex items-center justify-between"><label class="text-sm font-extrabold text-slate-800">Message</label>@if(isset($fieldSources['message']))<span class="text-xs font-bold text-slate-500">{{ $fieldSources['message']['label'] }}</span>@endif</div>
                                                <textarea name="payload[message]" rows="8" class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm">{{ old('payload.message', $editablePayload['message'] ?? '') }}</textarea>
                                                @error('payload.message')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                            </div>
                                        @else
                                            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">This payload type does not have a safe editor yet.</div>
                                        @endif

                                        <div class="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-sm text-slate-500">{{ $messageOverrideLayer ? 'This message currently has an explicit override.' : 'This message currently follows its shared/source content.' }}</p>
                                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white">Publish message override</button>
                                        </div>
                                    </form>
                                </div>

                                <aside class="space-y-4">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h3 class="text-sm font-extrabold text-slate-950">Exact published preview</h3>
                                        <p class="mt-1 text-xs text-slate-500">This is the resolved payload pinned by the current immutable version.</p>
                                        @if($selectedPreset->payload_class === \App\Modules\Messaging\Payloads\EmailPayload::class)
                                            <div class="mt-4 space-y-3 text-sm">
                                                <div><div class="text-xs font-bold uppercase tracking-wide text-slate-500">Subject</div><div class="mt-1 font-semibold text-slate-950">{{ $editablePayload['subject'] ?? '' }}</div></div>
                                                <div class="whitespace-pre-wrap rounded-xl border border-slate-200 bg-white p-3 text-slate-700">{{ $editablePayload['body'] ?? '' }}</div>
                                                @if(($editablePayload['cta']['label'] ?? '') || ($editablePayload['ctas'] ?? []))
                                                    <div class="rounded-xl bg-slate-950 px-3 py-2 text-center text-xs font-extrabold text-white">{{ $editablePayload['cta']['label'] ?? ($editablePayload['ctas'][0]['label'] ?? 'CTA') }}</div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="mt-4 whitespace-pre-wrap rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">{{ $editablePayload['message'] ?? '' }}</div>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                        <h3 class="text-sm font-extrabold text-slate-950">Used by</h3>
                                        @if($usageSummaries->isEmpty())
                                            <p class="mt-2 text-sm text-slate-500">Nothing currently selects this template.</p>
                                        @else
                                            <div class="mt-3 space-y-3">
                                                @foreach($usageSummaries as $usage)
                                                    <div class="rounded-xl bg-slate-50 p-3 text-sm">
                                                        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $usage['module_label'] }}</div>
                                                        <div class="mt-1 font-bold text-slate-950">{{ $usage['context_label'] }}</div>
                                                        <div class="mt-1 text-slate-600">{{ $usage['item_label'] }}</div>
                                                        @if($usage['url'])<a href="{{ $usage['url'] }}" class="mt-2 inline-flex text-xs font-extrabold text-indigo-700">Manage selection</a>@endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                        <h3 class="text-sm font-extrabold text-slate-950">Tokens used</h3>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @forelse($tokens as $token)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{ {{ $token }} }</span>@empty<span class="text-sm text-slate-500">No dynamic tokens.</span>@endforelse
                                        </div>
                                    </div>

                                    <details class="rounded-2xl border border-slate-200 bg-white p-4">
                                        <summary class="cursor-pointer text-sm font-extrabold text-slate-950">Details</summary>
                                        <dl class="mt-3 space-y-2 break-words text-xs text-slate-600">
                                            <div><dt class="font-bold text-slate-900">Message</dt><dd>{{ $selectedCatalogEntry?->item_label ?: $selectedPreset->message_type }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Template name</dt><dd>{{ $selectedPreset->name }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Template key</dt><dd>{{ $selectedPreset->key }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Source</dt><dd>{{ $selectedPreset->source_config_path ?: 'Database template' }}</dd></div>
                                        </dl>
                                    </details>
                                </aside>
                            </section>
                        </div>
                    @else
                        <div class="p-8 text-center">
                            <h2 class="text-xl font-extrabold tracking-tight text-slate-950">No template family selected.</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Adjust or clear the filters to choose a business-level message family.</p>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>