<x-layouts.crm
    title="Scheduled reports"
    heading="Scheduled reports"
    subheading="Email useful CRM reports automatically on the days and times you choose."
    module="reporting"
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">That scheduled report could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <a
                href="{{ route('crm.reporting.index') }}"
                class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4"
            >
                Back to Reporting
            </a>
        </div>

        @if($recipientOptions->isEmpty())
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 sm:p-7">
                <h2 class="font-semibold text-amber-950">Add a report recipient first</h2>
                <p class="mt-2 text-sm leading-6 text-amber-900">
                    No eligible scheduled-report recipients are available yet. Add or enable a report recipient in Settings &amp; setup, then return here to create the schedule.
                </p>
            </section>
        @endif

        @foreach($availableReports as $report)
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Create scheduled report</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ $report['label'] }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $report['description'] }}</p>

                <form method="POST" action="{{ route('crm.reporting.scheduled-reports.store') }}" class="mt-6 space-y-6">
                    @csrf
                    <input type="hidden" name="report_key" value="{{ $report['key'] }}">
                    <input type="hidden" name="is_enabled" value="1">

                    <div class="grid gap-4 lg:grid-cols-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Schedule name</label>
                            <input
                                name="name"
                                type="text"
                                required
                                value="{{ old('name', $report['label']) }}"
                                class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Send time</label>
                            <input
                                name="send_time"
                                type="time"
                                required
                                value="{{ old('send_time', '08:00') }}"
                                class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Timezone</label>
                            <select name="timezone" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                                @foreach($timezones as $timezone)
                                    <option value="{{ $timezone }}" @selected(old('timezone', $timezoneDefault) === $timezone)>
                                        {{ $timezone }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-semibold text-slate-800">Days</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($weekdays as $dayNumber => $dayLabel)
                                <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        name="days_of_week[]"
                                        value="{{ $dayNumber }}"
                                        @checked(in_array($dayNumber, old('days_of_week', [1, 2, 3, 4, 5]), true))
                                        class="rounded border-slate-300"
                                    >
                                    {{ $dayLabel }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-semibold text-slate-800">Recipients</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($recipientOptions as $recipient)
                                <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        name="recipient_keys[]"
                                        value="{{ $recipient->key }}"
                                        class="rounded border-slate-300"
                                    >
                                    <span>{{ $recipient->label }}</span>
                                    @if($recipient->email)
                                        <span class="text-xs text-slate-500">{{ $recipient->email }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @include($report['settings_view'], [
                        'parameters' => $report['parameters'],
                        'parameterData' => $report['settings_data'],
                    ])

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            @disabled($recipientOptions->isEmpty())
                            class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Create schedule
                        </button>
                    </div>
                </form>
            </section>
        @endforeach

        <section class="space-y-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-950">Current schedules</h2>
                <p class="mt-1 text-sm text-slate-600">Each schedule owns its recipients, days, time, and report filters.</p>
            </div>

            @forelse($subscriptions as $row)
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-semibold text-slate-950">{{ $row['subscription']->name }}</h3>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row['subscription']->is_enabled ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }}">
                                    {{ $row['subscription']->is_enabled ? 'Active' : 'Paused' }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">{{ $row['report_label'] }}</p>
                            @if($row['subscription']->next_send_at)
                                <p class="mt-2 text-xs text-slate-500">
                                    Next send {{ $row['subscription']->next_send_at->timezone($row['subscription']->timezone)->format('M j, Y g:i A T') }}
                                </p>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('crm.reporting.scheduled-reports.send-now', $row['subscription']) }}">
                                @csrf
                                <button class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                                    Send now
                                </button>
                            </form>
                            <form
                                method="POST"
                                action="{{ route('crm.reporting.scheduled-reports.destroy', $row['subscription']) }}"
                                onsubmit="return confirm('Delete this scheduled report?');"
                            >
                                @csrf
                                @method('DELETE')
                                <button class="rounded-xl border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.reporting.scheduled-reports.update', $row['subscription']) }}"
                        class="mt-6 space-y-6 border-t border-slate-200 pt-6"
                    >
                        @csrf
                        @method('PATCH')

                        <div class="grid gap-4 lg:grid-cols-3">
                            <div>
                                <label class="block text-sm font-semibold text-slate-800">Schedule name</label>
                                <input name="name" required value="{{ $row['subscription']->name }}" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-800">Send time</label>
                                <input name="send_time" type="time" required value="{{ $row['subscription']->send_time }}" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-800">Timezone</label>
                                <select name="timezone" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                                    @foreach($timezones as $timezone)
                                        <option value="{{ $timezone }}" @selected($row['subscription']->timezone === $timezone)>{{ $timezone }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-semibold text-slate-800">Days</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($weekdays as $dayNumber => $dayLabel)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            name="days_of_week[]"
                                            value="{{ $dayNumber }}"
                                            @checked(in_array($dayNumber, $row['subscription']->days_of_week ?? [], true))
                                            class="rounded border-slate-300"
                                        >
                                        {{ $dayLabel }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-semibold text-slate-800">Recipients</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($recipientOptions as $recipient)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            name="recipient_keys[]"
                                            value="{{ $recipient->key }}"
                                            @checked(in_array($recipient->key, $row['recipient_keys'], true))
                                            class="rounded border-slate-300"
                                        >
                                        {{ $recipient->label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        @if($row['settings_view'])
                            @include($row['settings_view'], [
                                'parameters' => $row['parameters'],
                                'parameterData' => $row['settings_data'],
                            ])
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                <input type="hidden" name="is_enabled" value="0">
                                <input
                                    type="checkbox"
                                    name="is_enabled"
                                    value="1"
                                    @checked($row['subscription']->is_enabled)
                                    class="rounded border-slate-300"
                                >
                                Active
                            </label>

                            <button class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white">
                                Save schedule
                            </button>
                        </div>
                    </form>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                    No scheduled reports yet.
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>