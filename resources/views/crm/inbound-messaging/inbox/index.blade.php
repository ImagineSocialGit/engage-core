@php
    $messages = $workspace['messages'];
    $filters = $workspace['filters'];
    $counts = $workspace['counts'];
    $throughOptions = $workspace['through_options'];

    $statusTabs = [
        'new' => ['label' => 'Needs review', 'count' => $counts['new']],
        'reviewed' => ['label' => 'In progress', 'count' => $counts['reviewed']],
        'done' => ['label' => 'Done', 'count' => $counts['done']],
        'all' => ['label' => 'All', 'count' => $counts['all']],
    ];
@endphp

<x-layouts.crm
    title="Inbox"
    heading="Inbox"
    subheading="Review every inbound email and text message, even when no automation or person match exists."
    module="inbound_messaging"
>
    <div class="space-y-6" data-inbound-inbox>
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-slate-950 px-3 py-2 text-sm font-semibold text-white">
                Inbox
            </span>
            <a
                href="{{ route('crm.inbound-messaging.email-routes.index') }}"
                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-950"
            >
                Inbound addresses
            </a>
            <a
                href="{{ route('crm.inbound-messaging.reply-profiles.index') }}"
                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-950"
            >
                Reply Handling
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">
                        Human review
                    </p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                        Nothing inbound gets lost just because automation is unavailable
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Messages stay here until someone marks them done. A message can be reviewed and linked to a person even when no automation or other optional feature is configured.
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center sm:min-w-[22rem]">
                    <div class="rounded-2xl bg-blue-50 px-3 py-3">
                        <p class="text-2xl font-semibold text-blue-950">{{ $counts['new'] }}</p>
                        <p class="text-xs font-semibold text-blue-700">Needs review</p>
                    </div>
                    <div class="rounded-2xl bg-slate-100 px-3 py-3">
                        <p class="text-2xl font-semibold text-slate-950">{{ $counts['reviewed'] }}</p>
                        <p class="text-xs font-semibold text-slate-600">In progress</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 px-3 py-3">
                        <p class="text-2xl font-semibold text-emerald-950">{{ $counts['done'] }}</p>
                        <p class="text-xs font-semibold text-emerald-700">Done</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap gap-2">
                @foreach($statusTabs as $value => $tab)
                    <a
                        href="{{ route('crm.inbound-messaging.inbox.index', array_filter([
                            'status' => $value,
                            'person' => $filters['person'] !== 'all' ? $filters['person'] : null,
                            'through' => $filters['through'] !== 'all' ? $filters['through'] : null,
                            'search' => $filters['search'] !== '' ? $filters['search'] : null,
                        ])) }}"
                        @class([
                            'rounded-full px-3 py-2 text-sm font-semibold',
                            'bg-slate-950 text-white' => $filters['status'] === $value,
                            'border border-slate-200 bg-white text-slate-700 hover:border-slate-300' => $filters['status'] !== $value,
                        ])
                    >
                        {{ $tab['label'] }}
                        <span class="ml-1 opacity-70">{{ $tab['count'] }}</span>
                    </a>
                @endforeach
            </div>

            <form
                method="GET"
                action="{{ route('crm.inbound-messaging.inbox.index') }}"
                class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(12rem,1fr)_minmax(12rem,1fr)_auto]"
            >
                <input type="hidden" name="status" value="{{ $filters['status'] }}">

                <div>
                    <label for="inbox-search" class="block text-sm font-semibold text-slate-800">
                        Search messages
                    </label>
                    <input
                        id="inbox-search"
                        name="search"
                        type="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Name, email, phone, subject, or message text"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                </div>

                <div>
                    <label for="inbox-through" class="block text-sm font-semibold text-slate-800">
                        Received through
                    </label>
                    <select
                        id="inbox-through"
                        name="through"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                        <option value="all">Everything</option>
                        @foreach($throughOptions as $option)
                            <option
                                value="{{ $option['value'] }}"
                                @selected($filters['through'] === $option['value'])
                            >
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="inbox-person" class="block text-sm font-semibold text-slate-800">
                        Person
                    </label>
                    <select
                        id="inbox-person"
                        name="person"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                        <option value="all" @selected($filters['person'] === 'all')>Everyone</option>
                        <option value="matched" @selected($filters['person'] === 'matched')>Matched to a person</option>
                        <option value="unmatched" @selected($filters['person'] === 'unmatched')>Not matched to a person</option>
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        Filter
                    </button>
                    <a
                        href="{{ route('crm.inbound-messaging.inbox.index', ['status' => $filters['status']]) }}"
                        class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:border-slate-400"
                    >
                        Clear
                    </a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            @forelse($messages as $row)
                @php($message = $row['message'])
                <a
                    href="{{ $row['href'] }}"
                    class="block border-b border-slate-100 px-5 py-5 transition last:border-b-0 hover:bg-slate-50 sm:px-7"
                    data-inbound-inbox-message
                >
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-blue-50 text-blue-700' => $row['status'] === 'new',
                                    'bg-slate-100 text-slate-700' => $row['status'] === 'reviewed',
                                    'bg-emerald-50 text-emerald-700' => $row['status'] === 'done',
                                ])>
                                    {{ $row['status_label'] }}
                                </span>

                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                    {{ $row['channel_label'] }}
                                </span>

                                <span class="text-xs font-semibold text-slate-500">
                                    {{ $row['received_at_label'] }}
                                </span>
                            </div>

                            <h2 class="mt-3 text-base font-semibold text-slate-950">
                                {{ $row['subject'] ?: $row['sender_label'] }}
                            </h2>

                            @if($row['subject'])
                                <p class="mt-1 text-sm text-slate-600">
                                    From {{ $row['sender_label'] }}
                                </p>
                            @endif

                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ $row['preview'] }}
                            </p>

                            @if(is_array($row['automated_response'] ?? null))
                                <div
                                    class="mt-3 inline-flex flex-wrap items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800"
                                    data-inbound-auto-response
                                >
                                    <span>System auto-responded by {{ $row['automated_response']['channel_label'] }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $row['automated_response']['status_label'] }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="shrink-0 lg:w-64">
                            <dl class="space-y-2 text-sm">
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Received through
                                    </dt>
                                    <dd class="mt-0.5 font-semibold text-slate-800">
                                        {{ $row['received_through'] }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Person
                                    </dt>
                                    <dd @class([
                                        'mt-0.5 font-semibold',
                                        'text-slate-800' => $row['person'],
                                        'text-amber-700' => ! $row['person'],
                                    ])>
                                        {{ $row['person_label'] }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </a>
            @empty
                <div class="px-6 py-14 text-center">
                    <h2 class="text-base font-semibold text-slate-900">
                        Nothing matches this view
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Try another status or remove one of the filters.
                    </p>
                </div>
            @endforelse
        </section>

        @if($messages->hasPages())
            <div>
                {{ $messages->links() }}
            </div>
        @endif
    </div>
</x-layouts.crm>