<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review upcoming appointments and open a focused scheduling workflow when you need to create one."
>
    <div class="space-y-6" data-scheduling-routine-workspace>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-configuration-link
            >
                Manage setup
            </a>
            <a
                href="{{ route('crm.scheduling.appointments.create') }}"
                class="inline-flex w-full items-center justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 sm:w-auto"
                data-scheduling-create-appointment
            >
                Schedule an appointment
            </a>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if (session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        @if (! $setupReadiness['internal_ready'])
            <x-ui.card class="space-y-4" data-scheduling-setup-readiness>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">
                            Setup needs attention
                        </div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                            Finish an appointment type before relying on booking
                        </h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                            An appointment type is ready for internal booking when its appointment method is complete, it has available hours, and any required staff assignment is active.
                        </p>
                    </div>
                    <a
                        href="{{ route('crm.scheduling.configuration.services.index') }}"
                        class="inline-flex w-full shrink-0 justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                    >
                        Review appointment types
                    </a>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Appointment method</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ $setupReadiness['has_complete_format'] ? 'Configured' : 'Needs setup' }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Availability</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ $setupReadiness['has_availability'] ? 'Hours available' : 'Needs setup' }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ready appointment types</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ $setupReadiness['internally_ready_service_count'] }} of {{ $setupReadiness['active_service_count'] }}</div>
                    </div>
                </div>
            </x-ui.card>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Upcoming</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $upcomingAppointments->count() }}</p>
                <p class="mt-1 text-sm text-slate-500">Active appointments starting from now forward.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Awaiting confirmation</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $pendingCount }}</p>
                <p class="mt-1 text-sm text-slate-500">Appointments that still need confirmation.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active appointment types</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $services->count() }}</p>
                <p class="mt-1 text-sm text-slate-500">Appointment types available to CRM staff.</p>
            </x-ui.card>
        </div>

        <x-ui.card class="overflow-hidden p-0" data-scheduling-upcoming-appointments>
            <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Upcoming appointments
                    </div>
                    <h2 class="mt-3 text-lg font-semibold tracking-tight text-slate-900">What is scheduled next</h2>
                </div>
                <a
                    href="{{ route('crm.scheduling.appointments.create') }}"
                    class="inline-flex w-full justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50 sm:w-auto"
                >
                    Schedule another appointment
                </a>
            </div>

            @if($upcomingAppointments->isEmpty())
                <div class="p-6 text-sm text-slate-500">
                    No upcoming appointments yet.
                </div>
            @else
                <div class="divide-y divide-slate-200">
                    @foreach($upcomingAppointmentRows as $row)
                        <article class="p-4 sm:p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <a href="{{ route('crm.scheduling.appointments.show', $row['appointment']) }}" class="font-semibold text-slate-900 hover:text-teal-700 hover:underline">{{ $row['title'] }}</a>
                                    <p class="mt-1 text-sm text-slate-600">{{ $row['contact_label'] }}</p>
                                    <p class="mt-2 text-sm font-medium text-slate-900">{{ $row['time_label'] }}</p>
                                    @if($row['host_label'])
                                        <p class="mt-1 text-xs text-slate-500">{{ $row['host_label'] }}</p>
                                    @endif
                                </div>
                                <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $row['status_class'] }}">{{ $row['status_label'] }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.crm>