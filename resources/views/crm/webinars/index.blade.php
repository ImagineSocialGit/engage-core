<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6">
        
        @if (session('zoom_sync_error'))
            <div
                x-data="{ open: true }"
                x-show="open"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            >
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-gray-950">
                        Zoom Sync Failed
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-gray-700">
                        {{ session('zoom_sync_error') }}
                    </p>

                    <div class="mt-6 flex justify-end">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if(session('sync_conflicts') && count(session('sync_conflicts')))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Active webinar conflicts detected.</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_conflicts') as $conflict)
                        <li class="flex items-center justify-between gap-4">
                            <span>
                                {{ $conflict['series'] }} — active: {{ $conflict['active'] }}, expected: {{ $conflict['expected'] }}
                            </span>

                            <form method="POST" action="{{ route('crm.webinar-series.fix-active', $conflict['webinar_series_id']) }}">
                                @csrf

                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500"
                                >
                                    Fix
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('sync_missing') && count(session('sync_missing')))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">Missing webinars preserved (not deleted).</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_missing') as $item)
                        <li>
                            {{ $item['title'] }} ({{ $item['status'] }})
                            @if($item['has_registrations'])
                                — has registrations
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between px-12 py-2">
                    <h2 class="text-sm font-semibold text-slate-900">
                        {{ $showArchived ? 'All Webinars' : 'Upcoming Webinars' }}
                    </h2>

                    <a
                        href="{{ $showArchived ? route('crm.webinar-series.index') : route('crm.webinar-series.index', ['archived' => 1]) }}"
                        class="text-sm font-medium text-slate-600 hover:text-slate-900 underline"
                    >
                        {{ $showArchived ? 'View Upcoming' : 'View Archived' }}
                    </a>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Title</th>
                            <th class="px-6 py-3">Series</th>
                            <th class="px-6 py-3">Start</th>
                            <th class="px-6 py-3">Timezone</th>
                            <th class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">
                        @forelse($webinars as $webinar)
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-medium text-slate-900">
                                    {{ $webinar->title }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $webinar->webinarSeries?->title ?? '—' }}
                                </td>

                                <td class="px-6 py-4 text-slate-700">
                                    {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $webinar->timezone }}
                                </td>
                                
                                <td class="px-6 py-4 text-right">
                                @php
                                    $registrationUrl = $webinar->webinarSeries?->slug
                                        ? rtrim(config('app.webinar_url') ?: config('app.url'), '/') . route('webinar.show', $webinar->webinarSeries->slug, false)
                                        : null;
                                @endphp

                                @if($registrationUrl)
                                    <div
                                        x-data="{ copied: false }"
                                        class="inline-flex items-center gap-2"
                                    >
                                        <a
                                            href="{{ $registrationUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-xs font-semibold text-slate-600 underline hover:text-slate-900"
                                        >
                                            View
                                        </a>

                                        <button
                                            type="button"
                                            x-on:click="
                                                const text = @js($registrationUrl);

                                                if (navigator.clipboard && window.isSecureContext) {
                                                    await navigator.clipboard.writeText(text);
                                                } else {
                                                    const textarea = document.createElement('textarea');
                                                    textarea.value = text;
                                                    textarea.style.position = 'fixed';
                                                    textarea.style.opacity = '0';
                                                    document.body.appendChild(textarea);
                                                    textarea.focus();
                                                    textarea.select();
                                                    document.execCommand('copy');
                                                    textarea.remove();
                                                }

                                                copied = true;
                                                setTimeout(() => copied = false, 1500);
                                            "
                                            class="inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700"
                                        >
                                            <span x-show="!copied">Copy Link</span>
                                            <span x-show="copied">Copied</span>
                                        </button>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">No link</span>
                                @endif
                            </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-6 text-sm text-slate-600">
                                    No webinars found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">
                        Add Series
                    </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.store') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="title" class="block text-sm font-medium text-slate-700">
                                Series Title
                            </label>

                            <input
                                id="title"
                                name="title"
                                type="text"
                                value="{{ old('title') }}"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                placeholder="Exact Zoom webinar series title"
                                required
                            >

                            @error('title')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        >
                            Add Series
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">
                        Sync Series
                    </h2>

                    <form method="POST" action="{{ route('crm.webinar-series.sync') }}" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label for="webinar_series_id" class="block text-sm font-medium text-slate-700">
                                Webinar Series
                            </label>

                            <select
                                id="webinar_series_id"
                                name="webinar_series_id"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                required
                            >
                                <option value="">Select a series</option>

                                @foreach($series as $seriesItem)
                                    <option
                                        value="{{ $seriesItem->id }}"
                                        @selected(old('webinar_series_id') == $seriesItem->id)
                                    >
                                        {{ $seriesItem->title }}
                                    </option>
                                @endforeach
                            </select>

                            @error('webinar_series_id')
                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        >
                            Sync from Zoom
                        </button>
                    </form>
                </div>

                @if($series->isNotEmpty())
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Existing Series
                        </h3>

                        <div class="mt-3 space-y-2">
                            @foreach($series as $seriesItem)
                                <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    {{ $seriesItem->title }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </div>
</x-layouts.crm>