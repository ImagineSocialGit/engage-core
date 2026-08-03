@php
    $nextAppointment = $upcomingAppointments->first();
    $otherUpcomingAppointments = $upcomingAppointments->skip(1)->values();
    $clientTimezone = config('client.timezone', config('app.timezone', 'UTC'));
    $statusClasses = fn (string $status): string => match($status) {
        \App\Modules\Scheduling\Models\Appointment::STATUS_PENDING => 'bg-amber-100 text-amber-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_CONFIRMED => 'bg-emerald-100 text-emerald-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_CANCELED => 'bg-slate-100 text-slate-700',
        \App\Modules\Scheduling\Models\Appointment::STATUS_NO_SHOW => 'bg-rose-100 text-rose-800',
        default => 'bg-sky-100 text-sky-800',
    };
    $displayTimezone = fn ($appointment): string => in_array(
        $appointment->timezone,
        timezone_identifiers_list(),
        true,
    ) ? $appointment->timezone : $clientTimezone;
@endphp

<x-ui.card
    class="space-y-5 {{ module_tone('scheduling', 'panel') }}"
    data-module-panel="scheduling"
    data-contact-id="{{ $contact->id }}"
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold tracking-tight">
                {{ $contactPanel->title }}
            </h3>

            <p class="mt-1 text-sm text-slate-600">
                Upcoming appointments and recent Scheduling outcomes for this {{ strtolower((string) config('contacts.labels.singular', 'contact')) }}.
            </p>
        </div>

        <x-ui.button
            href="{{ route('crm.scheduling.index', ['contact_id' => $contact->id]) }}"
            variant="secondary"
            data-scheduling-panel-action="schedule"
        >
            Schedule Appointment
        </x-ui.button>
    </div>

    @if($pendingAppointmentCount > 0)
        <div
            class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
            data-scheduling-panel-pending-count="{{ $pendingAppointmentCount }}"
        >
            <span class="font-semibold">
                {{ $pendingAppointmentCount }} {{ str('appointment')->plural($pendingAppointmentCount) }} awaiting confirmation.
            </span>
            Open the appointment record to confirm, cancel, reschedule, or review its lifecycle history.
        </div>
    @endif

    @if($nextAppointment)
        @php
            $nextTimezone = $displayTimezone($nextAppointment);
        @endphp

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Next appointment
                    </p>

                    <a
                        href="{{ route('crm.scheduling.appointments.show', $nextAppointment) }}"
                        class="mt-1 block font-semibold text-slate-950 hover:text-teal-700 hover:underline"
                        data-scheduling-appointment-kind="next"
                        data-appointment-id="{{ $nextAppointment->id }}"
                        data-appointment-status="{{ $nextAppointment->status }}"
                    >
                        {{ $nextAppointment->title ?: $nextAppointment->bookableService?->name ?: 'Appointment' }}
                    </a>

                    <p class="mt-2 text-sm font-medium text-slate-900">
                        {{ $nextAppointment->starts_at->setTimezone($nextTimezone)->format('D, M j, Y \a\t g:i A') }}
                        –
                        {{ $nextAppointment->ends_at->setTimezone($nextTimezone)->format('g:i A') }}
                    </p>

                    <p class="mt-1 text-xs text-slate-500">
                        {{ $nextTimezone }}
                        @if($nextAppointment->schedulingHost)
                            · {{ $nextAppointment->schedulingHost->name }}
                        @endif
                    </p>
                </div>

                <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses($nextAppointment->status) }}">
                    {{ str($nextAppointment->status)->replace('_', ' ')->title() }}
                </span>
            </div>
        </div>
    @endif

    @if($otherUpcomingAppointments->isNotEmpty())
        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Other upcoming
            </h4>

            <div class="mt-2 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach($otherUpcomingAppointments as $appointment)
                    @php
                        $appointmentTimezone = $displayTimezone($appointment);
                    @endphp

                    <a
                        href="{{ route('crm.scheduling.appointments.show', $appointment) }}"
                        class="flex flex-col gap-2 p-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                        data-scheduling-appointment-kind="upcoming"
                        data-appointment-id="{{ $appointment->id }}"
                        data-appointment-status="{{ $appointment->status }}"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-900">
                                {{ $appointment->title ?: $appointment->bookableService?->name ?: 'Appointment' }}
                            </span>
                            <span class="mt-1 block text-xs text-slate-500">
                                {{ $appointment->starts_at->setTimezone($appointmentTimezone)->format('M j, Y g:i A') }}
                                @if($appointment->schedulingHost)
                                    · {{ $appointment->schedulingHost->name }}
                                @endif
                            </span>
                        </span>

                        <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses($appointment->status) }}">
                            {{ str($appointment->status)->replace('_', ' ')->title() }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if($recentAppointments->isNotEmpty())
        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Recent outcomes
            </h4>

            <div class="mt-2 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach($recentAppointments as $appointment)
                    @php
                        $appointmentTimezone = $displayTimezone($appointment);
                        $replacement = $appointment->rescheduledAppointments->first();
                    @endphp

                    <a
                        href="{{ route('crm.scheduling.appointments.show', $appointment) }}"
                        class="flex flex-col gap-2 p-3 hover:bg-slate-50 sm:flex-row sm:items-start sm:justify-between"
                        data-scheduling-appointment-kind="recent"
                        data-appointment-id="{{ $appointment->id }}"
                        data-appointment-status="{{ $appointment->status }}"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-900">
                                {{ $appointment->title ?: $appointment->bookableService?->name ?: 'Appointment' }}
                            </span>
                            <span class="mt-1 block text-xs text-slate-500">
                                {{ $appointment->starts_at->setTimezone($appointmentTimezone)->format('M j, Y g:i A') }}
                            </span>

                            @if($appointment->rescheduledFrom)
                                <span class="mt-1 block text-xs font-medium text-slate-600">
                                    Replacement for an earlier appointment.
                                </span>
                            @elseif($replacement)
                                <span class="mt-1 block text-xs font-medium text-slate-600">
                                    Rescheduled to {{ $replacement->starts_at->setTimezone($displayTimezone($replacement))->format('M j, Y g:i A') }}.
                                </span>
                            @endif
                        </span>

                        <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses($appointment->status) }}">
                            {{ str($appointment->status)->replace('_', ' ')->title() }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if(! $nextAppointment && $recentAppointments->isEmpty())
        <div
            class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center"
            data-scheduling-panel-state="empty"
        >
            <p class="font-semibold text-slate-900">
                No appointments yet
            </p>
            <p class="mt-1 text-sm text-slate-500">
                Schedule an appointment without leaving this contact workflow.
            </p>
        </div>
    @endif
</x-ui.card>