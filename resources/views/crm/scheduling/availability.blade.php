<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Set when each service can be booked. Start with regular weekly hours, then add one-off changes when needed."
>
    @php
        $inputClass = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200';
        $labelClass = 'block text-sm font-medium text-slate-700';
        $weekdays = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
        $scopeOptions = [
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE => 'Service',
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_HOST => 'Staff/provider',
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE_HOST => 'Service + staff/provider',
        ];
        $activeWindows = $windows->reject(fn ($window) => $window->trashed())->values();
        $archivedWindows = $windows->filter(fn ($window) => $window->trashed())->values();
        $regularHoursState = $regularHours;
        $oldRegularHours = old('regular_hours');

        if (is_array($oldRegularHours)) {
            foreach ($regularHoursState as &$day) {
                $submitted = $oldRegularHours[$day['weekday']] ?? null;

                if (is_array($submitted)) {
                    $day['ranges'] = is_array($submitted['ranges'] ?? null)
                        ? array_values($submitted['ranges'])
                        : [];
                }
            }
            unset($day);
        }

        $specialHoursState = old('ranges', [
            ['start' => '09:00', 'end' => '17:00'],
        ]);
    @endphp

    <div class="space-y-6" data-scheduling-availability-configuration>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-availability-back
            >
                Back to configuration
            </a>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if ($errors->any())
            <x-ui.feedback.alert type="error">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.feedback.alert>
        @endif

        <datalist id="scheduling-availability-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        @if ($activeServices->isEmpty())
            <x-ui.card>
                <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center" data-availability-no-services>
                    <h2 class="text-lg font-semibold text-slate-900">Add a service first</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Availability belongs to something people can schedule. Create a service, then come back here to set its hours.
                    </p>
                    <a
                        href="{{ route('crm.scheduling.configuration.index') }}#services"
                        class="mt-4 inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        Add a service
                    </a>
                </div>
            </x-ui.card>
        @else
            <x-ui.card class="space-y-4" data-availability-service-selector>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl">
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Service
                        </div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                            Which service are you setting hours for?
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Each service can have its own regular hours and one-off changes.
                        </p>
                    </div>

                    <form method="GET" action="{{ route('crm.scheduling.configuration.availability.index') }}" class="flex w-full max-w-xl flex-col gap-2 sm:flex-row">
                        <label class="sr-only" for="availability-service">Service</label>
                        <select id="availability-service" class="{{ $inputClass }} mt-0" name="service_id" required>
                            @foreach ($activeServices as $service)
                                <option value="{{ $service->id }}" @selected((int) $selectedService?->id === (int) $service->id)>
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            View hours
                        </button>
                    </form>
                </div>
            </x-ui.card>

            @if ($selectedService)
                <x-ui.card class="space-y-5" data-availability-regular-hours>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                                Regular hours
                            </div>
                            <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                                When can people normally book {{ $selectedService->name }}?
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Add one or more time ranges to any day. Leave a day empty when the service is normally unavailable.
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Times below use <strong class="font-semibold text-slate-800">{{ $selectedService->timezone }}</strong>,
                        the timezone configured for this service.
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.scheduling.configuration.availability.regular-hours', $selectedService) }}"
                        class="space-y-4"
                        x-data="{
                            days: @js($regularHoursState),
                            addRange(day) {
                                day.ranges.push({ start: '09:00', end: '17:00' });
                            },
                            useWeekdayPreset() {
                                this.days = this.days.map((day) => ({
                                    ...day,
                                    ranges: day.weekday >= 1 && day.weekday <= 5
                                        ? [{ start: '09:00', end: '17:00' }]
                                        : []
                                }));
                            }
                        }"
                    >
                        @csrf
                        @method('PUT')

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                x-on:click="useWeekdayPreset()"
                            >
                                Use Monday–Friday, 9–5
                            </button>
                        </div>

                        <div class="divide-y divide-slate-200 rounded-xl border border-slate-200">
                            <template x-for="day in days" :key="day.weekday">
                                <div class="grid gap-3 p-4 lg:grid-cols-[10rem_1fr_auto] lg:items-start">
                                    <div>
                                        <p class="font-semibold text-slate-900" x-text="day.label"></p>
                                        <p class="mt-1 text-xs text-slate-500" x-show="day.ranges.length === 0">Unavailable</p>
                                        <input
                                            type="hidden"
                                            x-bind:name="`regular_hours[${day.weekday}][weekday]`"
                                            x-bind:value="day.weekday"
                                        >
                                    </div>

                                    <div class="space-y-2">
                                        <template x-for="(range, rangeIndex) in day.ranges" :key="`${day.weekday}-${rangeIndex}`">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                <div class="grid flex-1 grid-cols-2 gap-2">
                                                    <label>
                                                        <span class="sr-only">Start time</span>
                                                        <input
                                                            class="{{ $inputClass }} mt-0"
                                                            type="time"
                                                            x-bind:name="`regular_hours[${day.weekday}][ranges][${rangeIndex}][start]`"
                                                            x-model="range.start"
                                                            required
                                                        >
                                                    </label>
                                                    <label>
                                                        <span class="sr-only">End time</span>
                                                        <input
                                                            class="{{ $inputClass }} mt-0"
                                                            type="time"
                                                            x-bind:name="`regular_hours[${day.weekday}][ranges][${rangeIndex}][end]`"
                                                            x-model="range.end"
                                                            required
                                                        >
                                                    </label>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="text-sm font-semibold text-rose-700 hover:text-rose-800"
                                                    x-on:click="day.ranges.splice(rangeIndex, 1)"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </template>
                                    </div>

                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                        x-on:click="addRange(day)"
                                    >
                                        Add hours
                                    </button>
                                </div>
                            </template>
                        </div>

                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                            Save regular hours
                        </button>
                    </form>
                </x-ui.card>

                <div class="grid gap-6 xl:grid-cols-2">
                    <x-ui.card class="space-y-4" data-availability-special-hours>
                        <div>
                            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                                Special hours
                            </div>
                            <h2 class="mt-3 text-lg font-semibold text-slate-900">
                                Use different hours on one date
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                These hours replace the regular schedule for that date. Add more than one range when the day includes a break.
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.configuration.availability.special-hours', $selectedService) }}"
                            class="space-y-4"
                            x-data="{ ranges: @js($specialHoursState) }"
                        >
                            @csrf
                            @method('PUT')

                            <label class="{{ $labelClass }}">
                                Date
                                <input class="{{ $inputClass }}" type="date" name="date" value="{{ old('date') }}" required>
                            </label>

                            <div class="space-y-2">
                                <template x-for="(range, index) in ranges" :key="index">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                        <div class="grid flex-1 grid-cols-2 gap-2">
                                            <label>
                                                <span class="sr-only">Start time</span>
                                                <input
                                                    class="{{ $inputClass }} mt-0"
                                                    type="time"
                                                    x-bind:name="`ranges[${index}][start]`"
                                                    x-model="range.start"
                                                    required
                                                >
                                            </label>
                                            <label>
                                                <span class="sr-only">End time</span>
                                                <input
                                                    class="{{ $inputClass }} mt-0"
                                                    type="time"
                                                    x-bind:name="`ranges[${index}][end]`"
                                                    x-model="range.end"
                                                    required
                                                >
                                            </label>
                                        </div>
                                        <button
                                            type="button"
                                            class="text-sm font-semibold text-rose-700 hover:text-rose-800"
                                            x-show="ranges.length > 1"
                                            x-on:click="ranges.splice(index, 1)"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                    x-on:click="ranges.push({ start: '09:00', end: '17:00' })"
                                >
                                    Add another time range
                                </button>
                                <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                    Save special hours
                                </button>
                            </div>
                        </form>
                    </x-ui.card>

                    <x-ui.card class="space-y-4" data-availability-time-off>
                        <div>
                            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                                Time off
                            </div>
                            <h2 class="mt-3 text-lg font-semibold text-slate-900">
                                Make a date or part of a date unavailable
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Use this for holidays, appointments, vacations, or any one-off time when this service should not be booked.
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.configuration.availability.time-off', $selectedService) }}"
                            class="space-y-4"
                            x-data="{ allDay: @js((bool) old('all_day', true)) }"
                        >
                            @csrf
                            <input type="hidden" name="all_day" value="0">

                            <label class="{{ $labelClass }}">
                                Date
                                <input class="{{ $inputClass }}" type="date" name="date" value="{{ old('date') }}" required>
                            </label>

                            <label class="flex items-start gap-3 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    name="all_day"
                                    value="1"
                                    class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                                    x-model="allDay"
                                >
                                <span>
                                    <span class="font-semibold">Unavailable all day</span>
                                    <span class="mt-1 block text-xs text-slate-500">Turn this off to block only part of the day.</span>
                                </span>
                            </label>

                            <div class="grid gap-3 sm:grid-cols-2" x-show="!allDay" x-cloak>
                                <label class="{{ $labelClass }}">
                                    Start time
                                    <input
                                        class="{{ $inputClass }}"
                                        type="time"
                                        name="start_time"
                                        value="{{ old('start_time') }}"
                                        x-bind:disabled="allDay"
                                    >
                                </label>
                                <label class="{{ $labelClass }}">
                                    End time
                                    <input
                                        class="{{ $inputClass }}"
                                        type="time"
                                        name="end_time"
                                        value="{{ old('end_time') }}"
                                        x-bind:disabled="allDay"
                                    >
                                </label>
                            </div>

                            <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">
                                Add time off
                            </button>
                        </form>
                    </x-ui.card>
                </div>

                <section class="space-y-4" data-availability-date-changes>
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            One-off changes
                        </div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                            Upcoming special dates
                        </h2>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        @forelse ($dateChanges as $change)
                            <x-ui.card>
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="font-semibold text-slate-900">{{ $change['label'] }}</h3>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $change['type'] === 'special_hours' ? 'Special hours' : 'Time off' }}
                                        </p>
                                    </div>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ $selectedService->timezone }}
                                    </span>
                                </div>

                                <div class="mt-4 space-y-1 text-sm text-slate-700">
                                    @foreach ($change['ranges'] as $range)
                                        <p>
                                            @if ($range['start'] === '00:00' && $range['end'] === '24:00')
                                                Unavailable all day
                                            @else
                                                {{ \Carbon\CarbonImmutable::createFromFormat('!H:i', $range['start'] === '24:00' ? '00:00' : $range['start'])->format('g:i A') }}
                                                –
                                                {{ $range['end'] === '24:00' ? '12:00 AM next day' : \Carbon\CarbonImmutable::createFromFormat('!H:i', $range['end'])->format('g:i A') }}
                                            @endif
                                        </p>
                                    @endforeach
                                </div>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.availability.date-changes.destroy', [$selectedService, $change['date']]) }}"
                                    class="mt-4 border-t border-slate-200 pt-4"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="text-sm font-semibold text-rose-700 hover:text-rose-800"
                                        onclick="return window.confirm('Remove this one-off change? Regular hours will apply again for this date.')"
                                    >
                                        Remove this one-off change
                                    </button>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Regular hours will apply to this date again.
                                    </p>
                                </form>
                            </x-ui.card>
                        @empty
                            <x-ui.card>
                                <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                    No upcoming one-off changes for this service.
                                </div>
                            </x-ui.card>
                        @endforelse
                    </div>
                </section>

                <x-ui.card id="availability-preview" class="space-y-4 scroll-mt-6" data-availability-preview>
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Test availability
                        </div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                            Check what can actually be booked
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            This checks the same availability used when booking, including regular hours, one-off changes, staff assignments, how many appointments can happen at once, existing appointments, and other active limits.
                        </p>
                    </div>

                    <form method="GET" action="{{ route('crm.scheduling.configuration.availability.index').'#availability-preview' }}" class="grid gap-4 md:grid-cols-2">
                        <input type="hidden" name="service_id" value="{{ $selectedService->id }}">

                        <label class="{{ $labelClass }}">
                            Staff/provider
                            <select
                                class="{{ $inputClass }}"
                                name="preview_host_id"
                                @disabled($previewRequiresHost && $previewHosts->isEmpty())
                            >
                                @unless ($previewRequiresHost)
                                    <option value="">No specific staff member</option>
                                @endunless
                                @foreach ($previewHosts as $host)
                                    <option value="{{ $host->id }}" @selected((int) $previewHost?->id === (int) $host->id)>
                                        {{ $host->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($previewRequiresHost)
                                <span class="mt-1 block text-xs font-normal text-slate-500">
                                    This service uses explicit staff/provider assignments, so available times are tested for one assigned person at a time.
                                </span>
                            @endif
                        </label>

                        <label class="{{ $labelClass }}">
                            Date
                            <input class="{{ $inputClass }}" type="date" name="preview_date" value="{{ $previewDate }}" required>
                        </label>

                        <div class="md:col-span-2">
                            <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                                Check available times
                            </button>
                        </div>
                    </form>

                    @if ($previewRequiresHost && $previewHosts->isEmpty())
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-availability-preview-needs-staff>
                            This service has staff/provider assignments but none are currently active. Activate an assigned person before testing bookable times.
                        </div>
                    @endif

                    @if ($selectedService->usesFixedDuration())
                        <div class="space-y-2" data-availability-preview-ranges>
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Available appointment start times</h3>
                                <p class="mt-1 text-xs text-slate-500">Ranges summarize consecutive valid starts. Booking still uses one exact start time.</p>
                            </div>
                        </div>

                        @forelse ($previewStartRanges as $range)
                            @php
                                $rangeStart = $range['starts_at']->setTimezone($range['display_timezone']);
                                $rangeEnd = $range['last_start_at']->setTimezone($range['display_timezone']);
                            @endphp
                            <div
                                class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                                data-preview-start-range
                                data-preview-range-first="{{ $range['starts_at']->toISOString() }}"
                                data-preview-range-last="{{ $range['last_start_at']->toISOString() }}"
                                data-preview-range-count="{{ $range['slot_count'] }}"
                                data-preview-range-remaining="{{ $range['remaining_capacity'] }}"
                            >
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">
                                        {{ $rangeStart->format('M j, Y g:i A') }}{{ $range['slot_count'] > 1 ? '–'.$rangeEnd->format('g:i A') : '' }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $range['slot_count'] > 1 ? 'Start every '.$range['interval_minutes'].' minutes' : 'One available start' }} · {{ $range['display_timezone'] }}
                                    </p>
                                </div>
                                <p class="text-sm font-semibold text-slate-700">
                                    {{ $range['remaining_capacity'] }} open {{ $range['remaining_capacity'] === 1 ? 'spot' : 'spots' }} per start
                                </p>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500" data-availability-empty="preview">
                                No bookable times were found for this selection.
                            </div>
                        @endforelse
                    </div>

                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-[520px] divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th class="px-3 py-2">Available interval</th>
                                        <th class="px-3 py-2">Open spots</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($previewSlots as $slot)
                                        <tr data-preview-slot-start="{{ $slot->startsAt->toISOString() }}">
                                            <td class="px-3 py-3 font-medium text-slate-900">
                                                {{ $slot->localStartsAt()->format('M j, Y g:i A') }}–{{ $slot->localEndsAt()->format('g:i A T') }}
                                            </td>
                                            <td class="px-3 py-3 text-slate-700">{{ $slot->remainingCapacity }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="2" class="px-3 py-6 text-center text-slate-500" data-availability-empty="preview">No bookable intervals were found for this selection.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (($selectedService->usesFixedDuration() && $previewStartRanges !== []) || ($selectedService->usesRangeDuration() && $previewSlots !== []))
                        <div class="flex flex-col gap-2 rounded-xl border border-teal-200 bg-teal-50 p-4 sm:flex-row sm:items-center sm:justify-between" data-availability-preview-ready>
                            <div>
                                <p class="text-sm font-semibold text-teal-950">These times are ready to book.</p>
                                <p class="mt-1 text-xs text-teal-800">Continue to Scheduling to choose one of these times and finish a test or real appointment.</p>
                            </div>
                            <a
                                href="{{ route('crm.scheduling.index', array_filter([
                                    'bookable_service_id' => $selectedService->id,
                                    'scheduling_host_id' => $previewHost?->id,
                                    'date' => $previewDate,
                                ])) }}"
                                class="inline-flex justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                            >
                                Book an appointment
                            </a>
                        </div>
                    @endif
                </x-ui.card>

                <details class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-availability-advanced>
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">
                        Advanced availability rules
                    </summary>
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        Most businesses can stop with regular hours, special hours, and time off above. Use these controls only for staff-specific availability, custom capacity limits, connected-provider rules, or unusual scheduling policies.
                    </div>

                    <div class="mt-5 space-y-6">
        <x-ui.card class="space-y-5">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Advanced rule
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Add a staff-specific or advanced availability rule
                </h2>
            </div>

            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.availability.store') }}"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                data-availability-create
                x-data="{
                    scope: @js(old('scope', 'service')),
                    shape: @js(old('window_type', 'weekly'))
                }"
            >
                @csrf

                <label class="{{ $labelClass }}">
                    Behavior
                    <select class="{{ $inputClass }}" name="is_available" required>
                        <option value="1" @selected((string) old('is_available', '1') === '1')>Available</option>
                        <option value="0" @selected((string) old('is_available') === '0')>Unavailable</option>
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Applies to
                    <select class="{{ $inputClass }}" name="scope" x-model="scope" required>
                        @foreach ($scopeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="scope === 'service' || scope === 'service_host'">
                    Service
                    <select class="{{ $inputClass }}" name="bookable_service_id" x-bind:disabled="scope === 'host'">
                        <option value="">Select a service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected((int) old('bookable_service_id') === (int) $service->id)>
                                {{ $service->name }} · {{ $service->status }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="scope === 'host' || scope === 'service_host'">
                    Staff/provider
                    <select class="{{ $inputClass }}" name="scheduling_host_id" x-bind:disabled="scope === 'service'">
                        <option value="">Select staff/provider</option>
                        @foreach ($hosts as $host)
                            <option value="{{ $host->id }}" @selected((int) old('scheduling_host_id') === (int) $host->id)>
                                {{ $host->name }} · {{ $host->status }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Repeats
                    <select class="{{ $inputClass }}" name="window_type" x-model="shape" required>
                        <option value="weekly">Weekly recurring</option>
                        <option value="absolute">One-time date and time</option>
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Timezone
                    <input class="{{ $inputClass }}" name="timezone" list="scheduling-availability-timezones" value="{{ old('timezone', $defaultTimezone) }}" required>
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    Weekday
                    <select class="{{ $inputClass }}" name="weekday" x-bind:disabled="shape !== 'weekly'">
                        @foreach ($weekdays as $value => $label)
                            <option value="{{ $value }}" @selected((int) old('weekday', 1) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    Start time
                    <input class="{{ $inputClass }}" type="time" name="start_time" x-bind:disabled="shape !== 'weekly'" value="{{ old('start_time', '09:00') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    End time
                    <input class="{{ $inputClass }}" type="time" name="end_time" x-bind:disabled="shape !== 'weekly'" value="{{ old('end_time', '17:00') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                    Local start
                    <input class="{{ $inputClass }}" type="datetime-local" name="local_starts_at" x-bind:disabled="shape !== 'absolute'" value="{{ old('local_starts_at') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                    Local end
                    <input class="{{ $inputClass }}" type="datetime-local" name="local_ends_at" x-bind:disabled="shape !== 'absolute'" value="{{ old('local_ends_at') }}">
                </label>

                <label class="{{ $labelClass }}">
                    Capacity limit
                    <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ old('capacity') }}">
                    <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to use the service and staff limits that already apply.</span>
                </label>

                <div class="md:col-span-2 xl:col-span-4">
                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                        Add advanced rule
                    </button>
                </div>
            </form>
        </x-ui.card>

        <section class="space-y-4" data-availability-rules>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Active configuration
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Underlying availability rules
                </h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($activeWindows as $window)
                    @php
                        $editable = (bool) $window->getAttribute('crm_editable');
                        $scope = (string) $window->getAttribute('crm_scope');
                        $shape = $window->window_type->value;
                        $localStart = $window->starts_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i');
                        $localEnd = $window->ends_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i');
                    @endphp

                    <div
                        data-availability-window-id="{{ $window->id }}"
                        data-availability-source="{{ $window->source }}"
                        data-availability-archived="0"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-900">
                                        {{ $window->is_available ? 'Available' : 'Unavailable' }} · {{ $scopeOptions[$scope] ?? $scope }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $window->bookableService?->name ?? 'All services for host' }}
                                        @if ($window->schedulingHost)
                                            · {{ $window->schedulingHost->name }}
                                        @endif
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ $window->source }}
                                </span>
                            </div>

                            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt class="text-slate-500">Repeats</dt>
                                    <dd class="font-medium text-slate-900">{{ $shape }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Timezone</dt>
                                    <dd class="font-medium text-slate-900">{{ $window->timezone }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Capacity</dt>
                                    <dd class="font-medium text-slate-900">{{ $window->capacity ?? 'inherit' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Version</dt>
                                    <dd class="font-mono text-xs text-slate-900">{{ $window->updated_at?->toISOString() }}</dd>
                                </div>
                            </dl>

                            @if ($editable)
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-teal-700">Edit advanced rule</summary>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.scheduling.configuration.availability.update', $window) }}"
                                        class="mt-4 grid gap-4 md:grid-cols-2"
                                        data-availability-update="{{ $window->id }}"
                                        x-data="{ scope: @js($scope), shape: @js($shape) }"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">

                                        <label class="{{ $labelClass }}">
                                            Behavior
                                            <select class="{{ $inputClass }}" name="is_available" required>
                                                <option value="1" @selected($window->is_available)>Available</option>
                                                <option value="0" @selected(! $window->is_available)>Unavailable</option>
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Applies to
                                            <select class="{{ $inputClass }}" name="scope" x-model="scope" required>
                                                @foreach ($scopeOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="scope === 'service' || scope === 'service_host'">
                                            Service
                                            <select class="{{ $inputClass }}" name="bookable_service_id" x-bind:disabled="scope === 'host'">
                                                <option value="">Select a service</option>
                                                @foreach ($services as $service)
                                                    <option value="{{ $service->id }}" @selected((int) $window->bookable_service_id === (int) $service->id)>
                                                        {{ $service->name }} · {{ $service->status }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="scope === 'host' || scope === 'service_host'">
                                            Staff/provider
                                            <select class="{{ $inputClass }}" name="scheduling_host_id" x-bind:disabled="scope === 'service'">
                                                <option value="">Select staff/provider</option>
                                                @foreach ($hosts as $host)
                                                    <option value="{{ $host->id }}" @selected((int) $window->scheduling_host_id === (int) $host->id)>
                                                        {{ $host->name }} · {{ $host->status }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Repeats
                                            <select class="{{ $inputClass }}" name="window_type" x-model="shape" required>
                                                <option value="weekly">Weekly recurring</option>
                                                <option value="absolute">One-time date and time</option>
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Timezone
                                            <input class="{{ $inputClass }}" name="timezone" list="scheduling-availability-timezones" value="{{ $window->timezone }}" required>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            Weekday
                                            <select class="{{ $inputClass }}" name="weekday" x-bind:disabled="shape !== 'weekly'">
                                                @foreach ($weekdays as $value => $label)
                                                    <option value="{{ $value }}" @selected((int) $window->weekday === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            Start time
                                            <input class="{{ $inputClass }}" type="time" name="start_time" x-bind:disabled="shape !== 'weekly'" value="{{ $window->start_time ? substr((string) $window->start_time, 0, 5) : '' }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            End time
                                            <input class="{{ $inputClass }}" type="time" name="end_time" x-bind:disabled="shape !== 'weekly'" value="{{ $window->end_time ? substr((string) $window->end_time, 0, 5) : '' }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                                            Local start
                                            <input class="{{ $inputClass }}" type="datetime-local" name="local_starts_at" x-bind:disabled="shape !== 'absolute'" value="{{ $localStart }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                                            Local end
                                            <input class="{{ $inputClass }}" type="datetime-local" name="local_ends_at" x-bind:disabled="shape !== 'absolute'" value="{{ $localEnd }}">
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Capacity limit
                                            <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $window->capacity }}">
                                        </label>

                                        <div class="md:col-span-2">
                                            <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">
                                                Save advanced rule
                                            </button>
                                        </div>
                                    </form>
                                </details>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.availability.archive', $window) }}"
                                    data-availability-archive="{{ $window->id }}"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">
                                    <button type="submit" class="text-sm font-semibold text-rose-700 hover:text-rose-800">
                                        Archive advanced rule
                                    </button>
                                </form>
                            @else
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-availability-read-only="{{ $window->id }}">
                                    <p class="text-sm text-slate-600">This provider- or system-owned rule is visible for diagnosis but cannot be changed here.</p>
                                </div>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-availability-empty="active" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No active availability rules are configured.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-4" data-availability-archived-rules>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    History
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">Archived advanced rules</h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($archivedWindows as $window)
                    @php
                        $editable = (bool) $window->getAttribute('crm_editable');
                        $scope = (string) $window->getAttribute('crm_scope');
                    @endphp

                    <div
                        data-availability-window-id="{{ $window->id }}"
                        data-availability-archived="1"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-900">
                                        {{ $window->is_available ? 'Available' : 'Unavailable' }} · {{ $scopeOptions[$scope] ?? $scope }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $window->bookableService?->name ?? 'All services for host' }}
                                        @if ($window->schedulingHost)
                                            · {{ $window->schedulingHost->name }}
                                        @endif
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">archived</span>
                            </div>

                            @if ($editable)
                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.availability.restore', $window) }}"
                                    data-availability-restore="{{ $window->id }}"
                                >
                                    @csrf
                                    <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">
                                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">Restore advanced rule</button>
                                </form>
                            @else
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-availability-read-only="{{ $window->id }}">
                                    <p class="text-sm text-slate-600">This archived provider- or system-owned rule remains read-only.</p>
                                </div>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-availability-empty="archived" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No rules are archived.</div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

                    </div>
                </details>
            @endif
        @endif
    </div>
</x-layouts.crm>