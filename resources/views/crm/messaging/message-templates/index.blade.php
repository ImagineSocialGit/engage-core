<x-layouts.crm
    title="Message Templates"
    heading="Message Templates"
    subheading="Review one published message at a time, then edit it in the same frame."
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <p class="font-bold">The message could not be published.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">Messaging</p>
                    <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950">Message library</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Choose a message family, move through its messages with the carousel, and select Edit when the published copy needs to change. Save &amp; publish keeps immutable version history intact.
                    </p>
                </div>

                <div class="grid gap-2 sm:grid-cols-2 lg:min-w-80">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <span class="font-bold text-slate-950">{{ $presets->count() }}</span> messages
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <span class="font-bold text-slate-950">{{ $catalogGroups->count() }}</span> message families
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
            <div class="grid gap-6 xl:grid-cols-[minmax(17rem,0.36fr)_minmax(0,1fr)] xl:items-start">
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">Message families</h2>
                        <p class="mt-1 text-sm text-slate-500">Filter only when you need to narrow the library.</p>
                    </div>

                    <form method="GET" action="{{ route('crm.messaging.message-templates.index') }}" class="border-b border-slate-200 bg-slate-50 p-4 sm:p-5">
                        <div>
                            <label for="q" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">Search</label>
                            <input
                                id="q"
                                name="q"
                                type="search"
                                value="{{ $filters['q'] }}"
                                placeholder="Search names, subjects, copy, or families"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm"
                            >
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            @foreach([
                                ['key' => 'channel', 'label' => 'Channel', 'options' => $filterOptions['channels'], 'all' => 'All channels'],
                                ['key' => 'module', 'label' => 'Context', 'options' => $filterOptions['modules'], 'all' => 'All contexts'],
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

                        <details class="mt-3 rounded-xl border border-slate-200 bg-white px-3 py-2" @if($filters['purpose']) open @endif>
                            <summary class="cursor-pointer text-xs font-extrabold uppercase tracking-wide text-slate-500">Advanced filters</summary>
                            <div class="mt-3">
                                <label for="purpose" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-500">Purpose</label>
                                <select id="purpose" name="purpose" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm">
                                    <option value="">All purposes</option>
                                    @foreach($filterOptions['purposes'] as $option)
                                        <option value="{{ $option['value'] }}" @selected($filters['purpose'] === $option['value'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </details>

                        <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                            <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white">Search &amp; filter</button>
                            @if($filters['q'] || $filters['channel'] || $filters['purpose'] || $filters['module'])
                                <a href="{{ route('crm.messaging.message-templates.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700">Clear</a>
                            @endif
                        </div>
                    </form>

                    <div class="max-h-[44rem] divide-y divide-slate-100 overflow-y-auto">
                        @forelse($catalogGroups as $group)
                            @php
                                $firstEntry = $group['entries']->first();
                                $firstPreset = $firstEntry?->messageTemplatePreset;
                                $groupUrl = route('crm.messaging.message-templates.index', array_filter([
                                    'q' => $filters['q'],
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
                                        <div class="mt-1 text-xs text-slate-500">{{ $group['entries']->count() }} {{ \Illuminate\Support\Str::plural('message', $group['entries']->count()) }}</div>
                                    </div>
                                    @if($selected)
                                        <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800">Open</span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="p-6 text-center text-sm text-slate-500">No message families match this search and filter combination.</div>
                        @endforelse
                    </div>
                </section>

                <div class="space-y-6">
                    @if($selectedPreset && $selectedGroup)
                        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $selectedGroup['module_label'] }} · {{ strtoupper($selectedGroup['channel']) }}</p>
                                    <h2 class="mt-1 text-2xl font-black tracking-tight text-slate-950">{{ $selectedGroup['label'] }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">The carousel is the canonical message editor: published copy first, edit-in-place only when you ask for it.</p>
                                </div>
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    <div class="font-extrabold">Published{{ $currentTemplateVersion ? ' · v'.$currentTemplateVersion->version : '' }}</div>
                                    <div class="mt-1 text-xs leading-5">Existing scheduled messages keep their already-pinned immutable versions.</div>
                                </div>
                            </div>
                        </section>

                        <x-messaging.message-editor-carousel
                            :presentation="$messageLibrary"
                            :editable="true"
                            :token-fallbacks-editable="true"
                            :initial-message-id="'preset:'.$selectedPreset->getKey()"
                            empty-message="No messages are available in this family."
                        />

                        <details class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" @if($errors->any() && $sharedCompositionLayers->isNotEmpty()) open @endif>
                            <summary class="cursor-pointer list-none">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="text-lg font-extrabold text-slate-950">Advanced shared content &amp; usage</h3>
                                        <p class="mt-1 text-sm text-slate-600">Use this only when a value is intentionally shared across multiple messages. Normal copy editing belongs in the carousel above.</p>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ $selectedCatalogEntry?->item_label ?: $selectedPreset->name }}</span>
                                </div>
                            </summary>

                            <div class="mt-6 space-y-6">
                                @if($sharedCompositionLayers->isNotEmpty())
                                    <section class="space-y-4">
                                        <div>
                                            <h4 class="text-base font-extrabold text-slate-950">Shared content</h4>
                                            <p class="mt-1 text-sm text-slate-600">Publishing a shared change republishes only messages that currently inherit that changed layer.</p>
                                        </div>

                                        @foreach($sharedCompositionLayers as $shared)
                                            @php
                                                $layer = $shared['layer'];
                                                $layerPayload = is_array($layer->payload) ? $layer->payload : [];
                                            @endphp

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
                                                                <div>
                                                                    <label class="mb-1.5 block text-sm font-extrabold text-slate-800">{{ $field === 'cta' ? 'CTA' : 'Secondary link' }} label</label>
                                                                    <input name="payload[{{ $field }}][label]" value="{{ old('payload.'.$field.'.label', $value['label'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm">
                                                                </div>
                                                                <div>
                                                                    <label class="mb-1.5 block text-sm font-extrabold text-slate-800">URL</label>
                                                                    <input name="payload[{{ $field }}][url]" value="{{ old('payload.'.$field.'.url', $value['url'] ?? '') }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm">
                                                                </div>
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

                                <section class="grid gap-4 lg:grid-cols-3">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h4 class="text-sm font-extrabold text-slate-950">Used by</h4>
                                        @if($usageSummaries->isEmpty())
                                            <p class="mt-2 text-sm text-slate-500">Nothing currently selects this template.</p>
                                        @else
                                            <div class="mt-3 space-y-3">
                                                @foreach($usageSummaries as $usage)
                                                    <div class="rounded-xl bg-white p-3 text-sm">
                                                        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $usage['module_label'] }}</div>
                                                        <div class="mt-1 font-bold text-slate-950">{{ $usage['context_label'] }}</div>
                                                        <div class="mt-1 text-slate-600">{{ $usage['item_label'] }}</div>
                                                        @if($usage['url'])
                                                            <a href="{{ $usage['url'] }}" class="mt-2 inline-flex text-xs font-extrabold text-indigo-700">Manage selection</a>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h4 class="text-sm font-extrabold text-slate-950">Tokens used</h4>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @forelse($tokens as $token)
                                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">{ {{ $token }} }</span>
                                            @empty
                                                <span class="text-sm text-slate-500">No dynamic tokens.</span>
                                            @endforelse
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h4 class="text-sm font-extrabold text-slate-950">Technical details</h4>
                                        <dl class="mt-3 space-y-2 break-words text-xs text-slate-600">
                                            <div><dt class="font-bold text-slate-900">Template name</dt><dd>{{ $selectedPreset->name }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Template key</dt><dd>{{ $selectedPreset->key }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Source</dt><dd>{{ $selectedPreset->source_config_path ?: 'Database template' }}</dd></div>
                                            <div><dt class="font-bold text-slate-900">Message override</dt><dd>{{ $messageOverrideLayer ? 'Active' : 'Inherits shared/source content' }}</dd></div>
                                        </dl>
                                    </div>
                                </section>
                            </div>
                        </details>
                    @else
                        <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                            <h2 class="text-xl font-extrabold tracking-tight text-slate-950">No message family selected.</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Adjust or clear the filters to choose a message family.</p>
                        </section>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-layouts.crm>