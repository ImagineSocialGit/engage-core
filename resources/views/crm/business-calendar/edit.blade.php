@php
    $storedExclusions = $calendar->exclusions->map(fn ($exclusion): array => [
        'key' => (string) $exclusion->key,
        'name' => (string) $exclusion->name,
        'recurrence' => (string) $exclusion->recurrence,
        'exact_date' => $exclusion->exact_date?->toDateString(),
        'month' => $exclusion->month,
        'day' => $exclusion->day,
    ])->values()->all();
    $oldExclusions = old('exclusions', $storedExclusions);
    $exclusions = is_array($oldExclusions) ? array_values($oldExclusions) : $storedExclusions;
    $oldSkippedWeekdays = old('skipped_weekdays_present')
        ? old('skipped_weekdays', [])
        : $calendar->skippedWeekdays();
    $skippedWeekdays = array_map(
        'intval',
        is_array($oldSkippedWeekdays) ? $oldSkippedWeekdays : [],
    );
    $months = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];
@endphp

<x-layouts.crm
    title="Business days"
    heading="Business days"
    subheading="Choose which weekdays and dates should not count when the system waits a number of business days."
    module="core"
>
    <div class="mx-auto max-w-5xl">
        @if(session('status'))
            <div class="mb-6">
                <x-ui.feedback.alert type="success">
                    {{ session('status') }}
                </x-ui.feedback.alert>
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6">
                <x-ui.feedback.alert type="error">
                    <p class="font-semibold">Review the highlighted information and try again.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.feedback.alert>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('crm.business-calendar.update') }}"
            x-data="{
                exclusions: @js($exclusions),
                addDate() {
                    this.exclusions.push({
                        key: null,
                        name: '',
                        recurrence: 'annual',
                        exact_date: '',
                        month: 1,
                        day: 1,
                    });
                    this.$nextTick(() => {
                        const inputs = this.$root.querySelectorAll('[data-exclusion-name]');
                        inputs[inputs.length - 1]?.focus();
                    });
                },
                removeDate(index) {
                    this.exclusions.splice(index, 1);
                },
            }"
            class="space-y-6"
        >
            @csrf
            @method('PUT')
            <input type="hidden" name="skipped_weekdays_present" value="1">

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <h2 class="text-lg font-semibold tracking-tight text-slate-950">Days that do not count</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                    A business-day wait moves past every checked weekday. Most businesses skip Saturday and Sunday.
                </p>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($weekdays as $number => $weekday)
                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition has-checked:border-orange-400 has-checked:bg-orange-50">
                            <input
                                type="checkbox"
                                name="skipped_weekdays[]"
                                value="{{ $number }}"
                                @checked(in_array($number, $skippedWeekdays, true))
                                class="h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
                            >
                            <span>{{ $weekday }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">Dates that do not count</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                            Add holidays, office closures, or any other date that should be skipped. A date can repeat every year or apply only once.
                        </p>
                    </div>

                    <button
                        type="button"
                        x-on:click="addDate()"
                        class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                    >
                        Add date
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    <template x-if="exclusions.length === 0">
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-6 text-sm leading-6 text-slate-700">
                            No specific dates are being skipped yet.
                        </div>
                    </template>

                    <template x-for="(exclusion, index) in exclusions" x-bind:key="exclusion.key ?? `new-${index}`">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                            <input
                                type="hidden"
                                x-bind:name="`exclusions[${index}][key]`"
                                x-bind:value="exclusion.key ?? ''"
                            >

                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_14rem_auto] lg:items-end">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-900">Name</span>
                                    <input
                                        type="text"
                                        x-model="exclusion.name"
                                        x-bind:name="`exclusions[${index}][name]`"
                                        maxlength="255"
                                        required
                                        data-exclusion-name
                                        placeholder="Christmas Day"
                                        class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                    >
                                </label>

                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-900">How often?</span>
                                    <select
                                        x-model="exclusion.recurrence"
                                        x-bind:name="`exclusions[${index}][recurrence]`"
                                        class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                    >
                                        <option value="annual">Every year</option>
                                        <option value="once">One time</option>
                                    </select>
                                </label>

                                <button
                                    type="button"
                                    x-on:click="removeDate(index)"
                                    class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                                >
                                    Remove
                                </button>
                            </div>

                            <div class="mt-4">
                                <div x-show="exclusion.recurrence === 'annual'" class="grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-sm font-semibold text-slate-900">Month</span>
                                        <select
                                            x-model.number="exclusion.month"
                                            x-bind:name="exclusion.recurrence === 'annual' ? `exclusions[${index}][month]` : null"
                                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                        >
                                            @foreach($months as $monthNumber => $monthName)
                                                <option value="{{ $monthNumber }}">{{ $monthName }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="block">
                                        <span class="text-sm font-semibold text-slate-900">Day</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="31"
                                            x-model.number="exclusion.day"
                                            x-bind:name="exclusion.recurrence === 'annual' ? `exclusions[${index}][day]` : null"
                                            class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                        >
                                    </label>
                                </div>

                                <label x-show="exclusion.recurrence === 'once'" class="block max-w-sm">
                                    <span class="text-sm font-semibold text-slate-900">Date</span>
                                    <input
                                        type="date"
                                        x-model="exclusion.exact_date"
                                        x-bind:name="exclusion.recurrence === 'once' ? `exclusions[${index}][exact_date]` : null"
                                        class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                    >
                                </label>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <section class="rounded-3xl border border-orange-200 bg-orange-50 p-5 sm:p-6">
                <h2 class="font-semibold text-slate-950">What happens when this changes?</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    New business-day waits use the updated calendar. Anyone already waiting keeps the date and time that were calculated when their wait began.
                </p>
            </section>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a
                    href="{{ route('crm.flow-routes.index') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    Back to Routes
                </a>

                <button
                    type="submit"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl bg-orange-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                >
                    Save business days
                </button>
            </div>
        </form>
    </div>
</x-layouts.crm>