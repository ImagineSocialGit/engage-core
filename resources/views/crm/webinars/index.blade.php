<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6">
        @if(session('sync_conflicts') && count(session('sync_conflicts')))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Active webinar conflicts detected.</p>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach(session('sync_conflicts') as $conflict)
                        <li class="flex items-center justify-between gap-4">
                            <span>
                                {{ $conflict['series'] }} — active: {{ $conflict['active'] }}, expected: {{ $conflict['expected'] }}
                            </span>

                            <form method="POST" action="{{ route('crm.webinar-series.fix-active', $conflict['series_id']) }}">
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
                        href="{{ $showArchived ? route('crm.webinars.index') : route('crm.webinars.index', ['archived' => 1]) }}"
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
                            <th class="px-6 py-3">Status</th>
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
                                    {{ $webinar->series?->title ?? '—' }}
                                </td>

                                <td class="px-6 py-4 text-slate-700">
                                    {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $webinar->timezone }}
                                </td>

                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                        {{ ucfirst($webinar->status) }}
                                    </span>
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
                            <label for="series_id" class="block text-sm font-medium text-slate-700">
                                Webinar Series
                            </label>

                            <select
                                id="series_id"
                                name="series_id"
                                class="mt-1 block w-full rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                                required
                            >
                                <option value="">Select a series</option>

                                @foreach($series as $seriesItem)
                                    <option
                                        value="{{ $seriesItem->id }}"
                                        @selected(old('series_id') == $seriesItem->id)
                                    >
                                        {{ $seriesItem->title }}
                                    </option>
                                @endforeach
                            </select>

                            @error('series_id')
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