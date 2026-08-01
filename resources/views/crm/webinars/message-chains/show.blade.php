<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-bold">The message copy could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Series-owned messaging
                    </p>
                    <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950">
                        {{ $series->title }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        The schedule profile controls the default timing and copy. Creating a custom chain gives this series its own immutable versions without changing any other series.
                    </p>

                    <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-xs text-slate-600">
                        <div>
                            <dt class="font-bold text-slate-900">Schedule profile</dt>
                            <dd>{{ $scheduleProfile?->name ?? 'No active profile' }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-900">Ownership</dt>
                            <dd>{{ $bindings->isNotEmpty() ? 'Custom series chain' : 'Profile defaults' }}</dd>
                        </div>
                        @if ($bindings->isNotEmpty())
                            <div>
                                <dt class="font-bold text-slate-900">Bound areas</dt>
                                <dd>{{ $bindings->pluck('message_area_key')->unique()->count() }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <a
                    href="{{ route('crm.webinar-series.index') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                >
                    Back to webinars
                </a>
            </div>
        </section>

        @if ($bindings->isEmpty())
            <section class="rounded-3xl border border-indigo-200 bg-indigo-50/50 p-6 shadow-sm">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-700">
                        Create a custom chain
                    </p>
                    <h2 class="mt-2 text-xl font-black tracking-tight text-slate-950">
                        Duplicate the complete message sequence
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        The duplicated chain initially reuses immutable template versions. Editing a message then creates a series-owned template version and republishes only this series’ chain.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.webinar-series.message-chains.duplicate', $series) }}"
                    class="mt-6 max-w-xl space-y-4"
                >
                    @csrf

                    <div>
                        <label for="source_webinar_series_id" class="block text-sm font-bold text-slate-900">
                            Start from
                        </label>
                        <select
                            id="source_webinar_series_id"
                            name="source_webinar_series_id"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                        >
                            <option value="">This series’ effective schedule profile</option>
                            @foreach ($sourceSeriesOptions as $sourceSeries)
                                <option value="{{ $sourceSeries->getKey() }}">
                                    {{ $sourceSeries->title }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs leading-5 text-slate-600">
                            Choosing another series copies its effective message chains, including any published custom wording, without linking future edits.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white transition hover:bg-slate-800"
                    >
                        Create custom message chain
                    </button>
                </form>
            </section>
        @else
            <section class="rounded-3xl border border-amber-200 bg-amber-50/60 px-5 py-4 text-sm text-amber-950">
                <p class="font-bold">Published enrollments stay pinned.</p>
                <p class="mt-1 leading-6">
                    Saving copy publishes a new immutable template and chain version. Existing enrollments continue using the version they started with; new enrollments use the latest published version.
                </p>
            </section>

            <div class="space-y-6">
                @foreach ($chains as $chain)
                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <header class="border-b border-slate-200 bg-slate-50 px-6 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                                        {{ $chain['areas']->implode(' · ') }}
                                    </p>
                                    <h2 class="mt-1 text-xl font-black tracking-tight text-slate-950">
                                        {{ $chain['name'] }}
                                    </h2>
                                    @if ($chain['description'])
                                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                            {{ $chain['description'] }}
                                        </p>
                                    @endif
                                </div>

                                <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-extrabold text-slate-700 ring-1 ring-slate-200">
                                    Chain version {{ $chain['version'] }}
                                </span>
                            </div>
                        </header>

                        <div class="divide-y divide-slate-200">
                            @foreach ($chain['steps'] as $step)
                                <div class="px-6 py-6">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h3 class="text-base font-black text-slate-950">
                                                {{ $step['name'] }}
                                            </h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">
                                                {{ $step['timing'] }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                                        @foreach ($step['variants'] as $variant)
                                            @php
                                                $payload = $variant['payload'];
                                            @endphp

                                            <form
                                                method="POST"
                                                action="{{ route('crm.webinar-series.message-chains.variants.update', [$series, $variant['id']]) }}"
                                                class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
                                            >
                                                @csrf
                                                @method('PATCH')

                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-black text-slate-950">
                                                            {{ $variant['template_name'] }}
                                                        </p>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            {{ strtoupper($variant['channel']) }}
                                                            · {{ \Illuminate\Support\Str::headline($variant['purpose']) }}
                                                            · template version {{ $variant['template_version'] }}
                                                        </p>
                                                    </div>

                                                    <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-extrabold uppercase tracking-wide text-slate-600 ring-1 ring-slate-200">
                                                        {{ \Illuminate\Support\Str::headline($variant['message_type']) }}
                                                    </span>
                                                </div>

                                                <div class="mt-5 space-y-4">
                                                    @if ($variant['channel'] === 'email')
                                                        <div>
                                                            <label class="block text-sm font-bold text-slate-900">
                                                                Subject
                                                            </label>
                                                            <input
                                                                type="text"
                                                                name="payload[subject]"
                                                                value="{{ $payload['subject'] ?? '' }}"
                                                                maxlength="255"
                                                                required
                                                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                            >
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-bold text-slate-900">
                                                                Body
                                                            </label>
                                                            <textarea
                                                                name="payload[body]"
                                                                rows="10"
                                                                maxlength="10000"
                                                                required
                                                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                            >{{ $payload['body'] ?? '' }}</textarea>
                                                        </div>
                                                    @else
                                                        <div>
                                                            <label class="block text-sm font-bold text-slate-900">
                                                                SMS message
                                                            </label>
                                                            <textarea
                                                                name="payload[message]"
                                                                rows="6"
                                                                maxlength="1600"
                                                                required
                                                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                            >{{ $payload['message'] ?? '' }}</textarea>
                                                        </div>
                                                    @endif

                                                    @if (array_key_exists('footer', $payload))
                                                        <div>
                                                            <label class="block text-sm font-bold text-slate-900">
                                                                Footer
                                                            </label>
                                                            <textarea
                                                                name="payload[footer]"
                                                                rows="3"
                                                                maxlength="2000"
                                                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                            >{{ $payload['footer'] ?? '' }}</textarea>
                                                        </div>
                                                    @endif

                                                    @foreach (['cta' => 'Primary link', 'secondary_link' => 'Secondary link'] as $linkKey => $linkLabel)
                                                        @if (is_array($payload[$linkKey] ?? null))
                                                            <div class="grid gap-3 sm:grid-cols-2">
                                                                <div>
                                                                    <label class="block text-sm font-bold text-slate-900">
                                                                        {{ $linkLabel }} label
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        name="payload[{{ $linkKey }}][label]"
                                                                        value="{{ $payload[$linkKey]['label'] ?? '' }}"
                                                                        maxlength="255"
                                                                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                                    >
                                                                </div>

                                                                <div>
                                                                    <label class="block text-sm font-bold text-slate-900">
                                                                        {{ $linkLabel }} URL
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        name="payload[{{ $linkKey }}][url]"
                                                                        value="{{ $payload[$linkKey]['url'] ?? '' }}"
                                                                        maxlength="1000"
                                                                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                                    >
                                                                </div>
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>

                                                <button
                                                    type="submit"
                                                    class="mt-5 inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-4 text-sm font-extrabold text-white transition hover:bg-slate-800"
                                                >
                                                    Publish updated copy
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.crm>