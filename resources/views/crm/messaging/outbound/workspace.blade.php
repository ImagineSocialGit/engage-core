<div class="mx-auto max-w-7xl space-y-6">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900">{{ $errors->first() }}</div>
    @endif
    <form method="GET action="{{ route('crm.messaging.outbound.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block text-sm font-medium text-slate-700">Module
                <select name="module" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="">All modules</option>
                    @foreach($sources as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">When
                <select name="period" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="upcoming" @selected(($filters['period'] ?? 'upcoming') === 'upcoming')>Upcoming and actionable</option>
                    <option value="past" @selected(($filters['period'] ?? '') === 'past')>Past send times</option>
                    <option value="all" @selected(($filters['period'] ?? '') === 'all')>All times</option>
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Message status
                <select name="message_status" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="">All statuses</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['message_status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Channel
                <select name="channel" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="">All channels</option>
                    <option value="email" @selected(($filters['channel'] ?? '') === 'email')>Email</option>
                    <option value="sms" @selected(($filters['channel'] ?? '') === 'sms')>Text</option>
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Lead status
                <select name="contact_status" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="">All statuses</option>
                    @foreach(($contactOptions['status'] ?? []) as $option)
                        <option value="{{ $option['value'] }}" @selected(($filters['contact_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Lead tag
                <select name="tag" class="mt-1 w-full rounded-xl border-slate-300">
                    <option value="">All tags</option>
                    @foreach(($contactOptions['tag'] ?? []) as $option)
                        <option value="{{ $option['value'] }}" @selected(($filters['tag'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Lead search
                <input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="120" class="mt-1 w-full rounded-xl border-slate-300" placeholder="Name, email, or phone">
            </label>
            <label class="block text-sm font-medium text-slate-700">After
                <input type="date" name="after" value="{{ $filters['after'] ?? '' }}" class="mt-1 w-full rounded-xl border-slate-300">
            </label>
            <label class="block text-sm font-medium text-slate-700">Before
                <input type="date" name="before" value="{{ $filters['before'] ?? '' }}" class="mt-1 w-full rounded-xl border-slate-300">
            </label>
        </div>
        @if($embedded)<input type="hidden" name="embedded" value="1">@endif
        @if(isset($filters['group']))<input type="hidden" name="group" value="{{ $filters['group'] }}">@endif
        @if(isset($filters['scope'], $filters['scope_id']))
            <input type="hidden" name="scope" value="{{ $filters['scope'] }}">
            <input type="hidden" name="scope_id" value="{{ $filters['scope_id'] }}">
        @endif
        @if(isset($filters['contact_id']))
            <input type="hidden" name="contact_id" value="{{ $filters['contact_id'] }}">
        @endif
        @if(isset($filters['origin_type'], $filters['origin_id']))
            <input type="hidden" name="origin_type" value="{{ $filters['origin_type'] }}">
            <input type="hidden" name="origin_id" value="{{ $filters['origin_id'] }}">
        @endif
        <div class="mt-5 flex items-center gap-3">
            <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Apply filters</button>
            <a href="{{ route('crm.messaging.outbound.index', array_filter([
                'scope' => $filters['scope'] ?? null,
                'scope_id' => $filters['scope_id'] ?? null,
                'group' => $filters['group'] ?? null,
                'embedded' => $embedded ? 1 : null,
            ])) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">Clear refinements</a>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">{{ number_format($messages->total()) }} messages · Times shown in {{ $timezone }}</p>
        <p class="text-xs text-slate-500">Template excerpts may contain tokens; final content is resolved at send time.</p>
    </div>

    @forelse($rows as $row)
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-slate-950">{{ $row['recipient'] }} <span class="font-normal text-slate-500">· {{ $row['channel'] }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">{{ $row['destination'] }} · {{ $row['source'] }} · {{ $row['type'] }} · #{{ $row['id'] }}</p>
                    @if($row['subject'])<p class="mt-3 font-medium text-slate-900">{{ $row['subject'] }}</p>@endif
                    @if($row['preview'])<p class="mt-1 text-sm text-slate-700">{{ $row['preview'] }}</p>@endif
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold text-slate-950">{{ $row['send_at'] }}</p>
                    <p class="mt-1 text-xs font-medium text-slate-600">{{ $row['status'] }}</p>
                </div>
            </div>
            @if($canControl && $row['can_cancel'])
                <div class="mt-5 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                    @if($row['can_hold'])
                        <form method="POST" action="{{ route('crm.messaging.outbound.control', ['scheduledMessage' => $row['id']] + $returnFilters) }}">
                            @csrf
                            <input type="hidden" name="action" value="hold">
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">Hold</button>
                        </form>
                    @endif
                    @if($row['can_resume'])
                        <form method="POST" action="{{ route('crm.messaging.outbound.control', ['scheduledMessage' => $row['id']] + $returnFilters) }}">
                            @csrf
                            <input type="hidden" name="action" value="resume">
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">Resume</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('crm.messaging.outbound.control', ['scheduledMessage' => $row['id']] + $returnFilters) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <input type="hidden" name="action" value="reschedule">
                        <label class="text-xs font-medium text-slate-600">Change send time ({{ $timezone }})
                            <input type="datetime-local" name="send_at" value="{{ $row['send_at_input'] }}" required class="mt-1 block rounded-lg border-slate-300 text-sm">
                        </label>
                        <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">Save time</button>
                    </form>
                    <form method="POST" action="{{ route('crm.messaging.outbound.control', ['scheduledMessage' => $row['id']] + $returnFilters) }}" onsubmit="return confirm('Cancel this unsent message?')">
                        @csrf
                        <input type="hidden" name="action" value="cancel">
                        <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-medium text-rose-700">Cancel</button>
                    </form>
                </div>
            @endif
        </article>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-sm text-slate-600">No outbound messages match these filters.</div>
    @endforelse

    {{ $messages->links() }}
</div>