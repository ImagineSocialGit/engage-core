<x-layouts.crm
    title="Events"
    heading="Events"
    subheading="Catalog concrete occurrences, keep their schedule and location accurate, and move drafts forward when they are ready."
    module="events"
>
    <div class="space-y-6" data-events-workspace>
        @if (session('status'))
            <x-ui.feedback.alert type="success">{{ session('status') }}</x-ui.feedback.alert>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('crm.events.index') }}" class="grid flex-1 gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                <div>
                    <label class="sr-only" for="events_q">Search Events</label>
                    <input
                        id="events_q"
                        type="search"
                        name="q"
                        value="{{ $filters['q'] }}"
                        placeholder="Search title, venue, or city"
                        class="w-full rounded-lg border-slate-300 text-sm"
                    >
                </div>
                <div>
                    <label class="sr-only" for="events_status">Status</label>
                    <select id="events_status" name="status" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" variant="outline">Filter</x-ui.button>
            </form>

            <a href="{{ route('crm.events.create') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                Create Event
            </a>
        </div>

        <x-ui.card class="overflow-hidden p-0">
            @if ($events->isEmpty())
                <div class="px-6 py-12 text-center">
                    <h2 class="text-lg font-semibold text-slate-950">No Events found</h2>
                    <p class="mt-2 text-sm text-slate-600">Create a draft or change the filters to see another part of the catalog.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Event</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Starts</th>
                                <th class="px-5 py-3">Location</th>
                                <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($events as $event)
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <a href="{{ route('crm.events.show', $event) }}" class="font-semibold text-slate-950 hover:underline">
                                            {{ $event->title }}
                                        </a>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $typeLabels[$event->type_key] ?? ($event->type_key ?: 'No type') }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ module_tone('events', 'badge') }}">
                                            {{ $statusOptions[$event->status->value] ?? $event->status->value }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 align-top text-slate-700">
                                        <div>{{ $event->starts_at?->timezone($event->timezone)->format('M j, Y g:i A') }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $event->timezone }}</div>
                                    </td>
                                    <td class="px-5 py-4 align-top text-slate-700">
                                        <div>{{ $event->venue_name ?: '—' }}</div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ collect([$event->city, $event->region])->filter()->implode(', ') ?: 'No city' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right align-top">
                                        <a href="{{ route('crm.events.show', $event) }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{ $events->links() }}
    </div>
</x-layouts.crm>