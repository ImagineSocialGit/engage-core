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
            href="{{ route('crm.scheduling.appointments.create', ['contact_id' => $contact->id]) }}"
            variant="secondary"
            class="w-full sm:w-auto"
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

    @if($nextAppointmentPresentation)
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Next appointment
                    </p>

                    <a
                        href="{{ $nextAppointmentPresentation['show_url'] }}"
                        class="mt-1 block font-semibold text-slate-950 hover:text-teal-700 hover:underline"
                        data-scheduling-appointment-kind="next"
                        data-appointment-id="{{ $nextAppointmentPresentation['id'] }}"
                        data-appointment-status="{{ $nextAppointmentPresentation['status'] }}"
                    >
                        {{ $nextAppointmentPresentation['title'] }}
                    </a>

                    <p class="mt-2 text-sm font-medium text-slate-900">
                        {{ $nextAppointmentPresentation['starts_at_label'] }}
                        –
                        {{ $nextAppointmentPresentation['ends_at_label'] }}
                    </p>

                    <p class="mt-1 text-xs text-slate-500">
                        {{ $nextAppointmentPresentation['timezone'] }}
                        @if($nextAppointmentPresentation['host_name'])
                            · {{ $nextAppointmentPresentation['host_name'] }}
                        @endif
                    </p>
                </div>

                <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $nextAppointmentPresentation['status_classes'] }}">
                    {{ $nextAppointmentPresentation['status_label'] }}
                </span>
            </div>
        </div>
    @endif

    @if($otherUpcomingAppointmentPresentations !== [])
        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Other upcoming
            </h4>

            <div class="mt-2 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach($otherUpcomingAppointmentPresentations as $appointment)
                    <a
                        href="{{ $appointment['show_url'] }}"
                        class="flex flex-col gap-2 p-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                        data-scheduling-appointment-kind="upcoming"
                        data-appointment-id="{{ $appointment['id'] }}"
                        data-appointment-status="{{ $appointment['status'] }}"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-900">
                                {{ $appointment['title'] }}
                            </span>
                            <span class="mt-1 block text-xs text-slate-500">
                                {{ $appointment['starts_at_label'] }}
                                @if($appointment['host_name'])
                                    · {{ $appointment['host_name'] }}
                                @endif
                            </span>
                        </span>

                        <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $appointment['status_classes'] }}">
                            {{ $appointment['status_label'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if($recentAppointmentPresentations !== [])
        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Recent outcomes
            </h4>

            <div class="mt-2 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach($recentAppointmentPresentations as $appointment)
                    <a
                        href="{{ $appointment['show_url'] }}"
                        class="flex flex-col gap-2 p-3 hover:bg-slate-50 sm:flex-row sm:items-start sm:justify-between"
                        data-scheduling-appointment-kind="recent"
                        data-appointment-id="{{ $appointment['id'] }}"
                        data-appointment-status="{{ $appointment['status'] }}"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-900">
                                {{ $appointment['title'] }}
                            </span>
                            <span class="mt-1 block text-xs text-slate-500">
                                {{ $appointment['starts_at_label'] }}
                            </span>

                            @if($appointment['reschedule_note'])
                                <span class="mt-1 block text-xs font-medium text-slate-600">
                                    {{ $appointment['reschedule_note'] }}
                                </span>
                            @endif
                        </span>

                        <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $appointment['status_classes'] }}">
                            {{ $appointment['status_label'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if(! $hasAnyAppointment)
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