<x-layouts.crm
    :title="$campaign->name.' audience'"
    heading="Campaign audience"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div class="min-w-0 space-y-6">
        <a href="{{ route('crm.campaigns.show', $campaign) }}" class="inline-flex text-sm font-semibold text-slate-600 hover:text-slate-950">
            &larr; Campaign overview
        </a>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Audience and progress</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Counts and lists below include leads you can view. Filter matches reflect the current Campaign start rules. Message consent and other delivery checks happen separately.
                    </p>
                </div>
                <a href="{{ route('crm.campaigns.edit', ['campaign' => $campaign, 'panel' => 'start']) }}" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    Edit start rules
                </a>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'view' => 'matching']) }}" class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 hover:bg-slate-100">
                    <span class="block text-2xl font-bold text-slate-950">{{ number_format($summary['matching']) }}</span>
                    <span class="mt-1 block text-sm text-slate-600">Match start rules now</span>
                </a>
                <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'view' => 'matching']) }}" class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 hover:bg-slate-100">
                    <span class="block text-2xl font-bold text-slate-950">{{ number_format($summary['not_started']) }}</span>
                    <span class="mt-1 block text-sm text-slate-600">Match and never enrolled</span>
                </a>
                <a href="{{ route('crm.campaigns.audience.index', $campaign) }}" class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 hover:bg-slate-100">
                    <span class="block text-2xl font-bold text-slate-950">{{ number_format($summary['enrolled']) }}</span>
                    <span class="mt-1 block text-sm text-slate-600">Ever enrolled</span>
                </a>
                <a href="{{ route('crm.campaigns.audience.index', $campaign) }}" class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 hover:bg-emerald-100">
                    <span class="block text-2xl font-bold text-emerald-950">{{ number_format($summary['statuses']['active'] + $summary['statuses']['paused']) }}</span>
                    <span class="mt-1 block text-sm text-emerald-800">Open enrollments</span>
                </a>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($statusFilters as $filter)
                    <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'status' => $filter]) }}" class="rounded-full bg-white px-3 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                        {{ \Illuminate\Support\Str::headline($filter) }} {{ number_format($summary['statuses'][$filter]) }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ $viewMode === 'matching' ? 'Matching leads' : 'Enrollment progress' }}</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $viewMode === 'matching' ? 'Current filter matches, including leads who have never enrolled.' : 'Each lead’s most recent enrollment in this Campaign.' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'view' => 'participants']) }}" class="rounded-full px-4 py-2 text-sm font-semibold {{ $viewMode === 'participants' ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700' }}">Progress</a>
                    <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'view' => 'matching']) }}" class="rounded-full px-4 py-2 text-sm font-semibold {{ $viewMode === 'matching' ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700' }}">Matches</a>
                </div>
            </div>

            <form method="GET" action="{{ route('crm.campaigns.audience.index', $campaign) }}" class="mt-5 flex flex-wrap items-end gap-3">
                <input type="hidden" name="view" value="{{ $viewMode }}">
                @if($viewMode === 'participants')
                    <div>
                        <label for="audience-status" class="block text-xs font-semibold text-slate-600">Enrollment status</label>
                        <select id="audience-status" name="status" class="mt-1 rounded-xl border-slate-300 text-sm">
                            <option value="">All statuses</option>
                            @foreach($statusFilters as $filter)
                                <option value="{{ $filter }}" @selected($statusFilter === $filter)>{{ \Illuminate\Support\Str::headline($filter) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="min-w-56 flex-1">
                    <label for="audience-search" class="block text-xs font-semibold text-slate-600">Lead</label>
                    <input id="audience-search" type="search" name="search" value="{{ $search }}" placeholder="Name, email, or phone" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <button type="submit" class="min-h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
                <a href="{{ route('crm.campaigns.audience.index', ['campaign' => $campaign, 'view' => $viewMode]) }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-slate-600 hover:text-slate-950">Clear filters</a>
            </form>

            <p class="mt-5 text-xs font-semibold text-slate-500">{{ number_format($rows->total()) }} {{ \Illuminate\Support\Str::plural('lead', $rows->total()) }} in this view</p>

            <div class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-2xl border border-slate-200">
                @forelse($rows as $row)
                    <div class="grid gap-3 p-4 text-sm sm:grid-cols-3 sm:gap-5">
                        <div class="min-w-0">
                            <a href="{{ route('crm.contacts.show', $row['contact']) }}" class="break-words font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">{{ $row['name'] }}</a>
                            <div class="mt-1 break-all text-xs text-slate-600">{{ $row['contact']->email ?: $row['contact']->phone ?: 'No contact method' }}</div>
                        </div>
                        <div>
                            <div class="font-semibold text-slate-800">{{ \Illuminate\Support\Str::headline($row['status']) }}</div>
                            @if($viewMode === 'participants')
                                @if($row['step'])<div class="mt-1 break-words text-slate-600">Step: {{ $row['step'] }}</div>@endif
                                @if($row['version'])<div class="mt-1 text-xs text-slate-500">Schedule version {{ $row['version'] }}</div>@endif
                            @endif
                        </div>
                        @if($viewMode === 'participants')
                            <div class="min-w-0 text-slate-600">
                                @if($row['next_at'])<div>Next action: {{ $row['next_at']->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}</div>@endif
                                @if($row['message_status'])<div>Last message: {{ \Illuminate\Support\Str::headline($row['message_status']) }}</div>@endif
                                @if($row['reason'])<div class="mt-1 break-words text-xs text-amber-800">Reason: {{ $row['reason'] }}</div>@endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="p-6 text-sm text-slate-600">No leads in this view.</p>
                @endforelse
            </div>

            @if($rows->hasPages())
                <div class="mt-5">{{ $rows->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.crm>