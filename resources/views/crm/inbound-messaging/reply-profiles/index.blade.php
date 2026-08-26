@php
    $profiles = $workspace['profiles'];
    $selectedProfile = $workspace['selected_profile'];
    $selectedDefinition = $workspace['selected_definition'];
    $selectedDependencies = $workspace['selected_dependencies'];
    $dependencyCounts = $workspace['dependency_counts'];
    $selectedIntentValues = old('form_mode') === 'update'
        ? old('intents', $selectedDefinition['intents'] ?? [])
        : ($selectedDefinition['intents'] ?? []);
@endphp

<x-layouts.crm
    title="Reply Handling"
    heading="Reply Handling"
    subheading="Decide how ordinary replies are recognized and see what depends on each outcome."
    module="inbound_messaging"
>
    <div
        class="space-y-6"
        data-reply-handling-workspace
        x-data="{ createOpen: @js(old('form_mode') === 'create') }"
        x-on:keydown.escape.window="createOpen = false"
    >
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">Reply Handling was not changed.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex justify-end">
            <a
                href="{{ route('crm.inbound-messaging.email-routes.index') }}"
                class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950"
            >
                Inbound addresses
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">Inbound replies</p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                        Turn reply language into dependable outcomes
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        A reply profile travels with an outgoing message. When the contact responds, exact replies are checked first, then keyword phrases. The resulting intent can start a Route or another configured response.
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <div class="rounded-2xl bg-slate-100 px-4 py-3 text-center">
                        <p class="text-2xl font-semibold text-slate-950">{{ $workspace['active_count'] }}</p>
                        <p class="text-xs font-semibold text-slate-600">Active profiles</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="createOpen = true"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                        Add reply profile
                    </button>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[19rem_minmax(0,1fr)]">
            <aside class="self-start rounded-3xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-6">
                <div class="px-2 pb-3">
                    <h2 class="text-sm font-semibold text-slate-950">Profiles</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Select one to review its vocabulary and dependencies.</p>
                </div>

                <nav class="space-y-2" aria-label="Reply profiles">
                    @forelse($profiles as $profile)
                        @php($dependencyCount = (int) ($dependencyCounts[$profile->key] ?? 0))
                        <a
                            href="{{ route('crm.inbound-messaging.reply-profiles.index', ['profile' => $profile->key]) }}"
                            @class([
                                'block rounded-2xl border px-3 py-3 transition',
                                'border-blue-300 bg-blue-50 shadow-sm' => $selectedProfile?->is($profile),
                                'border-transparent bg-slate-50 hover:border-slate-200 hover:bg-white' => ! $selectedProfile?->is($profile),
                            ])
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-950">{{ $profile->label }}</span>
                                    <span class="mt-1 block truncate font-mono text-[0.68rem] text-slate-500">{{ $profile->key }}</span>
                                </span>
                                <span @class([
                                    'mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full',
                                    'bg-emerald-500' => $profile->is_active,
                                    'bg-slate-300' => ! $profile->is_active,
                                ])></span>
                            </span>
                            <span class="mt-2 block text-xs text-slate-500">
                                {{ $profile->intents->count() }} {{ Str::plural('intent', $profile->intents->count()) }}
                                · {{ $dependencyCount }} {{ Str::plural('dependency', $dependencyCount) }}
                            </span>
                        </a>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center">
                            <p class="text-sm font-semibold text-slate-800">No reply profiles yet</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Add one or run the reply-profile sync command.</p>
                        </div>
                    @endforelse
                </nav>
            </aside>

            <main class="min-w-0">
                @if($selectedProfile)
                    <div class="space-y-6">
                        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-xs font-semibold ring-1',
                                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $selectedProfile->is_active,
                                            'bg-slate-100 text-slate-600 ring-slate-200' => ! $selectedProfile->is_active,
                                        ])>
                                            {{ $selectedProfile->is_active ? 'Active' : 'Disabled' }}
                                        </span>
                                        @if($selectedProfile->is_customized)
                                            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-800 ring-1 ring-blue-200">Customized</span>
                                        @endif
                                    </div>
                                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-950">{{ $selectedProfile->label }}</h2>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $selectedProfile->key }}</p>
                                    @if($selectedProfile->description)
                                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ $selectedProfile->description }}</p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('crm.inbound-messaging.reply-profiles.state', $selectedProfile) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $selectedProfile->is_active ? 0 : 1 }}">
                                        <button
                                            type="submit"
                                            @disabled($selectedProfile->is_active && $selectedDependencies->isNotEmpty())
                                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45"
                                        >
                                            {{ $selectedProfile->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.inbound-messaging.reply-profiles.destroy', $selectedProfile) }}"
                                        onsubmit="return confirm('Remove this unused reply profile?')"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            @disabled($selectedDependencies->isNotEmpty())
                                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-45"
                                        >
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            </div>

                            @if($selectedDependencies->isNotEmpty())
                                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                                    This profile cannot be disabled or removed while the dependencies below still reference it. Its recognition rules remain editable.
                                </div>
                            @endif
                        </section>

                        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-reply-profile-dependencies>
                            <div class="flex items-end justify-between gap-4">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Dependencies</p>
                                    <h2 class="mt-2 text-lg font-semibold text-slate-950">Where this profile is used</h2>
                                </div>
                                <span class="text-sm font-semibold text-slate-600">{{ $selectedDependencies->count() }}</span>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @forelse($selectedDependencies as $dependency)
                                    <article class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="rounded-full bg-white px-2 py-0.5 text-[0.68rem] font-bold uppercase tracking-[0.08em] text-slate-600 ring-1 ring-slate-200">
                                                        {{ $dependency->moduleKey === 'flow_routes' ? 'Flow Route' : 'Messaging' }}
                                                    </span>
                                                    @if($dependency->intentKey)
                                                        <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[0.68rem] font-semibold text-blue-800 ring-1 ring-blue-200">
                                                            {{ Str::headline($dependency->intentKey) }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <h3 class="mt-2 text-sm font-semibold text-slate-950">{{ $dependency->label }}</h3>
                                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $dependency->detail }}</p>
                                            </div>
                                            @if($dependency->url)
                                                <a href="{{ $dependency->url }}" class="shrink-0 text-xs font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Open</a>
                                            @endif
                                        </div>
                                    </article>
                                @empty
                                    <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center md:col-span-2">
                                        <p class="text-sm font-semibold text-slate-800">This profile is not currently referenced.</p>
                                        <p class="mt-1 text-xs text-slate-500">It may be disabled or removed safely.</p>
                                    </div>
                                @endforelse
                            </div>
                        </section>

                        <section
                            class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"
                            data-reply-profile-editor
                            x-data="{
                                intents: @js($selectedIntentValues).map((intent, index) => ({ ...intent, client_key: `${intent.key}-${index}` })),
                                nextKey: {{ count($selectedIntentValues) }},
                                addIntent() {
                                    this.intents.push({
                                        client_key: `new-${this.nextKey++}`,
                                        key: '',
                                        label: '',
                                        description: '',
                                        is_active: true,
                                        exact: '',
                                        keywords: '',
                                    });
                                },
                            }"
                        >
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Recognition rules</p>
                                <h2 class="mt-2 text-lg font-semibold text-slate-950">What should a reply mean?</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Changes apply only to future replies. Existing inbound-message classifications are not rewritten.</p>
                            </div>

                            <form method="POST" action="{{ route('crm.inbound-messaging.reply-profiles.update', $selectedProfile) }}" class="mt-5 space-y-6">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="form_mode" value="update">
                                <input type="hidden" name="key" value="{{ $selectedProfile->key }}">

                                <div class="grid gap-4 md:grid-cols-2">
                                    <label class="block">
                                        <span class="text-sm font-semibold text-slate-800">Profile name</span>
                                        <input name="label" value="{{ old('form_mode') === 'update' ? old('label') : $selectedProfile->label }}" required class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </label>
                                    <label class="block md:col-span-2">
                                        <span class="text-sm font-semibold text-slate-800">Description <span class="font-normal text-slate-500">(optional)</span></span>
                                        <textarea name="description" rows="2" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('form_mode') === 'update' ? old('description') : $selectedProfile->description }}</textarea>
                                    </label>
                                </div>

                                <div class="space-y-4">
                                    <template x-for="(intent, index) in intents" :key="intent.client_key">
                                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" data-reply-intent-editor>
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-blue-700">Intent <span x-text="index + 1"></span></p>
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">Exact replies win before keyword matching.</p>
                                                </div>
                                                <button type="button" x-on:click="intents.splice(index, 1)" class="text-xs font-semibold text-red-700 underline decoration-red-200 underline-offset-4">Remove intent</button>
                                            </div>

                                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                                <label class="block">
                                                    <span class="text-sm font-semibold text-slate-800">Intent key</span>
                                                    <input x-model="intent.key" x-bind:name="`intents[${index}][key]`" required placeholder="high_intent" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-sm font-semibold text-slate-800">User-facing name</span>
                                                    <input x-model="intent.label" x-bind:name="`intents[${index}][label]`" required placeholder="High intent" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </label>
                                                <label class="block md:col-span-2">
                                                    <span class="text-sm font-semibold text-slate-800">Description <span class="font-normal text-slate-500">(optional)</span></span>
                                                    <input x-model="intent.description" x-bind:name="`intents[${index}][description]`" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-sm font-semibold text-slate-800">Exact replies</span>
                                                    <textarea x-model="intent.exact" x-bind:name="`intents[${index}][exact]`" rows="5" placeholder="YES&#10;CALL ME&#10;SEND IT" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                                    <span class="mt-1 block text-xs leading-5 text-slate-500">One complete reply per line. Use this for short outcomes such as NO.</span>
                                                </label>
                                                <label class="block">
                                                    <span class="text-sm font-semibold text-slate-800">Keyword phrases</span>
                                                    <textarea x-model="intent.keywords" x-bind:name="`intents[${index}][keywords]`" rows="5" placeholder="READY&#10;DOWN PAYMENT&#10;GAME PLAN" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                                    <span class="mt-1 block text-xs leading-5 text-slate-500">One bounded phrase per line. Matching ignores case.</span>
                                                </label>
                                            </div>

                                            <label class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                                                <input type="hidden" x-bind:name="`intents[${index}][is_active]`" value="0">
                                                <input type="checkbox" x-model="intent.is_active" x-bind:name="`intents[${index}][is_active]`" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                                Intent is active
                                            </label>
                                        </article>
                                    </template>
                                </div>

                                <div class="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                    <button type="button" x-on:click="addIntent()" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Add intent</button>
                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Save reply handling</button>
                                </div>
                            </form>
                        </section>
                    </div>
                @else
                    <section class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm">
                        <h2 class="text-lg font-semibold text-slate-950">Add the first reply profile</h2>
                        <p class="mt-2 text-sm text-slate-600">Start with the outgoing-message context and the reply phrases that mean high intent.</p>
                        <button type="button" x-on:click="createOpen = true" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Add reply profile</button>
                    </section>
                @endif
            </main>
        </div>

        <div x-cloak x-show="createOpen" x-transition.opacity x-on:click.self="createOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4">
            <section x-show="createOpen" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Add reply profile">
                <form method="POST" action="{{ route('crm.inbound-messaging.reply-profiles.store') }}">
                    @csrf
                    <input type="hidden" name="form_mode" value="create">
                    <input type="hidden" name="intents[0][key]" value="high_intent">
                    <input type="hidden" name="intents[0][label]" value="High intent">
                    <input type="hidden" name="intents[0][is_active]" value="1">

                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">New profile</p>
                            <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">What outgoing conversation is this for?</h2>
                        </div>
                        <button type="button" x-on:click="createOpen = false" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 text-slate-600" aria-label="Close">×</button>
                    </header>

                    <div class="space-y-5 px-5 py-5 sm:px-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-800">Profile name</span>
                                <input name="label" value="{{ old('form_mode') === 'create' ? old('label') : '' }}" required placeholder="Cold lead nurture replies" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </label>
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-800">Stable key</span>
                                <input name="key" value="{{ old('form_mode') === 'create' ? old('key') : '' }}" required placeholder="cold_lead_nurture" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-sm font-semibold text-slate-800">Description <span class="font-normal text-slate-500">(optional)</span></span>
                                <textarea name="description" rows="2" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('form_mode') === 'create' ? old('description') : '' }}</textarea>
                            </label>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h3 class="text-sm font-semibold text-slate-950">High-intent replies</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Add at least one exact reply or keyword. Additional intents can be added after creation.</p>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-800">Exact replies</span>
                                    <textarea name="intents[0][exact]" rows="5" placeholder="YES&#10;CALL ME" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('form_mode') === 'create' ? old('intents.0.exact') : '' }}</textarea>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-800">Keyword phrases</span>
                                    <textarea name="intents[0][keywords]" rows="5" placeholder="READY&#10;APPLY&#10;GAME PLAN" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('form_mode') === 'create' ? old('intents.0.keywords') : '' }}</textarea>
                                </label>
                            </div>
                        </div>
                    </div>

                    <footer class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4 sm:px-6">
                        <button type="button" x-on:click="createOpen = false" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Create profile</button>
                    </footer>
                </form>
            </section>
        </div>
    </div>
</x-layouts.crm>