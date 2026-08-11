@php
    $displayTimezone = in_array($service->timezone, timezone_identifiers_list(), true)
        ? $service->timezone
        : 'UTC';
    $currentHostLabel = $appointment->schedulingHost?->name ?? 'Unhosted';
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose a currently available replacement time without changing the appointment's service or attendee identity."
>
    <div class="space-y-6">
        @if (session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        <a
            href="{{ route('crm.scheduling.appointments.show', $appointment) }}"
            class="inline-flex text-sm font-semibold text-teal-700 hover:text-teal-900 hover:underline"
        >
            ← Back to Appointment
        </a>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <div class="space-y-6">
                <x-ui.card class="space-y-4">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Current appointment
                        </div>
                        <h2 class="mt-3 text-lg font-semibold tracking-tight text-slate-900">
                            {{ $appointment->title ?: $service->name }}
                        </h2>
                    </div>

                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ $service->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current time</dt>
                            <dd class="mt-1 font-medium text-slate-900">
                                {{ $appointment->starts_at?->setTimezone($displayTimezone)->format('D, M j, Y \a\t g:i A') }}
                                –
                                {{ $appointment->ends_at?->setTimezone($displayTimezone)->format($service->usesRangeDuration() ? 'D, M j, Y \a\t g:i A' : 'g:i A') }}
                            </dd>
                            <dd class="mt-1 text-xs text-slate-500">{{ $displayTimezone }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current host</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ $currentHostLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current status</dt>
                            <dd class="mt-1 font-medium text-slate-900">
                                {{ str($appointment->status)->replace('_', ' ')->title() }}
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-900">
                            Find a replacement time
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            @if($service->usesRangeDuration())
                                The current stay duration is preserved. Choose a replacement check-in date; Scheduling validates the full resulting check-in/check-out interval against all other appointments, holds, resources, blackouts, and capacity limits.
                            @else
                                The current appointment is excluded from availability, but all other appointments, holds, buffers, blackouts, and capacity limits still apply.
                            @endif
                        </p>
                    </div>

                    <form
                        method="GET"
                        action="{{ route('crm.scheduling.appointments.reschedule', $appointment) }}"
                        class="space-y-4"
                    >
                        @if($requiresHost)
                            <div>
                                <x-ui.form.label for="scheduling_host_id">
                                    Host
                                </x-ui.form.label>

                                <x-ui.form.select
                                    id="scheduling_host_id"
                                    name="scheduling_host_id"
                                    onchange="this.form.submit()"
                                >
                                    <option value="">Choose a host</option>

                                    @foreach($hosts as $host)
                                        <option
                                            value="{{ $host->id }}"
                                            @selected($selectedHost?->is($host))
                                        >
                                            {{ $host->name }}
                                        </option>
                                    @endforeach
                                </x-ui.form.select>

                                @if($hosts->isEmpty())
                                    <p class="mt-2 text-xs font-semibold text-amber-700">
                                        This service has no active assigned host.
                                    </p>
                                @endif
                            </div>
                        @endif

                        <div>
                            <x-ui.form.label for="date">
                                {{ $service->usesRangeDuration() ? 'Replacement check-in date' : 'Date' }}
                            </x-ui.form.label>

                            <x-ui.form.input
                                id="date"
                                name="date"
                                type="date"
                                value="{{ $selectedDate->toDateString() }}"
                                min="{{ $dateMinimum->toDateString() }}"
                                max="{{ $dateMaximum->toDateString() }}"
                                onchange="this.form.submit()"
                            />

                            @unless($dateInRange)
                                <p class="mt-2 text-xs font-semibold text-amber-700">
                                    Choose a date between {{ $dateMinimum->format('M j, Y') }} and {{ $dateMaximum->format('M j, Y') }}.
                                </p>
                            @endunless
                        </div>
                    </form>
                </x-ui.card>
            </div>

            <x-ui.card class="space-y-5">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Replacement appointment
                    </div>
                    <h2 class="mt-3 text-lg font-semibold tracking-tight text-slate-900">
                        Select the new time
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Rescheduling creates a replacement Appointment and preserves durable lineage to the canceled original.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.appointments.reschedule.store', [
                        'appointment' => $appointment,
                        'scheduling_host_id' => $selectedHost?->getKey(),
                        'date' => $selectedDate->toDateString(),
                    ]) }}"
                    class="space-y-5"
                >
                    @csrf

                    <input type="hidden" name="scheduling_host_id" value="{{ $selectedHost?->getKey() }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                    @if($suggestedSlots !== [])
                        <div class="rounded-2xl border border-teal-200 bg-teal-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <span class="text-sm font-semibold text-teal-950">Suggested open times</span>
                                    <p class="mt-1 text-xs leading-5 text-teal-800">
                                        These preserve the current service, location, host, resource, availability, and travel rules.
                                    </p>
                                </div>
                                <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-teal-800">
                                    Best fit first
                                </span>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach($suggestedSlots as $slot)
                                    @php
                                        $slotValue = $slot->startsAt->toISOString();
                                        $slotLocalStart = $slot->startsAt->setTimezone($displayTimezone);
                                        $slotLocalEnd = $slot->endsAt->setTimezone($displayTimezone);
                                    @endphp

                                    <label class="cursor-pointer rounded-xl border border-teal-200 bg-white p-3 hover:border-teal-400 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-100">
                                        <input
                                            type="radio"
                                            name="starts_at"
                                            value="{{ $slotValue }}"
                                            class="sr-only"
                                            @checked(old('starts_at') === $slotValue)
                                        >
                                        <span class="block text-sm font-semibold text-slate-900">
                                            @if($service->usesRangeDuration())
                                                {{ $slotLocalStart->format('D, M j, Y \a\t g:i A') }} – {{ $slotLocalEnd->format('D, M j, Y \a\t g:i A') }}
                                            @else
                                                {{ $slotLocalStart->format('D, M j') }} · {{ $slotLocalStart->format('g:i A') }}–{{ $slotLocalEnd->format('g:i A') }}
                                            @endif
                                        </span>
                                        <span class="mt-1 block text-xs text-slate-500">
                                            {{ $displayTimezone }}
                                            @if($slot->totalTravelMinutes() !== null)
                                                · travel-aware
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div>
                        <span class="block text-sm font-medium text-slate-700">
                            Available time
                        </span>

                        @if($requiresHost && ! $selectedHost)
                            <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                Choose an active assigned host before selecting a replacement time.
                            </p>
                        @elseif($slots === [])
                            <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                                No replacement times are currently available for this date.
                            </p>
                        @else
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach($slots as $slot)
                                    @php
                                        $slotValue = $slot->startsAt->toISOString();
                                        $slotLocalStart = $slot->startsAt->setTimezone($displayTimezone);
                                        $slotLocalEnd = $slot->endsAt->setTimezone($displayTimezone);
                                        $slotLabel = $service->usesRangeDuration()
                                            ? $slotLocalStart->format('D, M j, Y \a\t g:i A').' – '.$slotLocalEnd->format('D, M j, Y \a\t g:i A')
                                            : $slotLocalStart->format('g:i A').'–'.$slotLocalEnd->format('g:i A');
                                    @endphp

                                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 hover:border-slate-400 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                                        <input
                                            type="radio"
                                            name="starts_at"
                                            value="{{ $slotValue }}"
                                            class="sr-only"
                                            @checked(old('starts_at') === $slotValue)
                                        >
                                        <span class="block text-sm font-semibold text-slate-900">
                                            {{ $slotLabel }}
                                        </span>
                                        <span class="mt-1 block text-xs text-slate-500">
                                            {{ $displayTimezone }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        <x-ui.form.error name="starts_at" />
                        <x-ui.form.error name="scheduling_host_id" />
                        <x-ui.form.error name="idempotency_key" />
                    </div>

                    <div>
                        <x-ui.form.label for="reschedule_reason">
                            Reschedule reason
                        </x-ui.form.label>
                        <textarea
                            id="reschedule_reason"
                            name="reschedule_reason"
                            rows="4"
                            maxlength="10000"
                            class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        >{{ old('reschedule_reason') }}</textarea>
                        <x-ui.form.error name="reschedule_reason" />
                    </div>

                    @if($canPreserveConfirmation)
                        <input type="hidden" name="preserve_confirmation" value="0">

                        <label class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
                            <input
                                type="checkbox"
                                name="preserve_confirmation"
                                value="1"
                                class="mt-0.5 rounded border-sky-400"
                                @checked(old('preserve_confirmation', true))
                            >
                            <span>
                                Keep this confirmed appointment confirmed after moving it. Clear this choice to require confirmation again.
                            </span>
                        </label>
                        <x-ui.form.error name="preserve_confirmation" />
                    @endif

                    @if($requiresNoticeOverride)
                        <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <input
                                type="checkbox"
                                name="override_reschedule_notice"
                                value="1"
                                class="mt-0.5 rounded border-amber-400"
                                @checked(old('override_reschedule_notice'))
                            >
                            <span>
                                Override the {{ $noticeMinutes }}-minute reschedule notice requirement and record that authorization in lifecycle provenance.
                            </span>
                        </label>
                        <x-ui.form.error name="override_reschedule_notice" />
                    @endif

                    <x-ui.button
                        type="submit"
                        class="w-full justify-center"
                        :disabled="($requiresHost && ! $selectedHost) || $slots === []"
                    >
                        {{ $service->usesRangeDuration() ? 'Reschedule stay' : 'Reschedule Appointment' }}
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-layouts.crm>