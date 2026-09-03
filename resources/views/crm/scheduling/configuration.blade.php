<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Manage the pieces that make Scheduling work without turning setup into one giant form."
>
    <div class="space-y-6" data-scheduling-configuration>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-configuration-back
            >
                Back to Scheduling
            </a>

            <p class="text-sm text-slate-500">
                Start with Services, then add availability. Staff assignment is optional.
            </p>
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

        <x-ui.card class="space-y-4" data-scheduling-setup-status>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Setup status
                    </div>
                    <h2 class="mt-3 text-lg font-semibold text-slate-900">
                        {{ $readiness['internal_ready'] ? 'Scheduling is ready for appointments' : 'Finish the core booking setup' }}
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        A service and usable availability are the only requirements for internal scheduling. Staff/provider records are optional unless appointments need explicit assignment.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 text-center sm:min-w-64">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                        <div class="text-xl font-semibold text-slate-900">{{ $readiness['active_service_count'] }}</div>
                        <div class="mt-1 text-xs text-slate-500">Active services</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                        <div class="text-xl font-semibold text-slate-900">{{ $readiness['active_host_count'] }}</div>
                        <div class="mt-1 text-xs text-slate-500">Active staff</div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 p-3" data-scheduling-readiness-service="{{ $readiness['has_service'] ? 'ready' : 'needed' }}">
                    <div class="text-sm font-semibold text-slate-900">Services</div>
                    <div class="mt-1 text-xs text-slate-500">
                        {{ $readiness['has_service'] ? 'At least one active service is ready.' : 'Add the first thing people can schedule.' }}
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3" data-scheduling-readiness-availability="{{ $readiness['has_availability'] ? 'ready' : 'needed' }}">
                    <div class="text-sm font-semibold text-slate-900">Availability</div>
                    <div class="mt-1 text-xs text-slate-500">
                        {{ $readiness['has_availability'] ? 'Bookable hours are configured.' : 'Set the normal hours when appointments can happen.' }}
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3" data-scheduling-readiness-public="{{ $readiness['public_ready'] ? 'ready' : 'not_ready' }}">
                    <div class="text-sm font-semibold text-slate-900">Public booking</div>
                    <div class="mt-1 text-xs text-slate-500">
                        @if (! $readiness['public_surface_enabled'])
                            Public booking is not enabled for this deployment.
                        @elseif ($readiness['public_ready'])
                            Public booking has the required service and availability setup.
                        @elseif ($readiness['has_incomplete_public_service'])
                            One or more public services still need a complete appointment format.
                        @else
                            Choose at least one public service and finish the required setup.
                        @endif
                    </div>
                </div>
            </div>
        </x-ui.card>

        <section class="space-y-4" data-scheduling-setup-areas>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Setup areas
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Configure one thing at a time
                </h2>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <a
                    href="{{ route('crm.scheduling.configuration.services.index') }}"
                    class="block rounded-2xl border border-teal-200 bg-white p-5 shadow-sm transition hover:border-teal-300 hover:shadow"
                    data-scheduling-setup-area="services"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-teal-700">1. Services</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">What can people schedule?</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Add services, edit appointment format and booking rules, and choose who can handle each service.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.availability.index') }}"
                    class="block rounded-2xl border border-teal-200 bg-white p-5 shadow-sm transition hover:border-teal-300 hover:shadow"
                    data-scheduling-setup-area="availability"
                    data-scheduling-availability-configuration-link
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-teal-700">2. Availability</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">When can appointments happen?</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Set normal weekly hours, special dates, time off, and test the actual times Scheduling can offer.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.staff.index') }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 hover:shadow"
                    data-scheduling-setup-area="staff"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-slate-600">Optional</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">Staff & providers</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Add people only when appointments need explicit assignment or person-specific capacity.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.communications.index') }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 hover:shadow"
                    data-scheduling-setup-area="communications"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-slate-600">Appointment communications</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">Confirmations & reminders</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Manage the confirmation and reminder schedule used for appointments.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.after-booking.index') }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 hover:shadow"
                    data-scheduling-setup-area="after_booking"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-slate-600">After booking</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">What happens after someone books?</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Keep follow-up manual or connect the appropriate automation behavior.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.resources.index') }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 hover:shadow"
                    data-scheduling-setup-area="resources"
                    data-scheduling-resource-configuration-link
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-slate-600">Advanced</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">Rooms, equipment & shared capacity</h3>
                            <p class="mt-2 text-sm text-slate-500">
                                Configure limited shared resources only when a booking depends on them.
                            </p>
                        </div>
                        <span class="text-slate-400">→</span>
                    </div>
                </a>
            </div>
        </section>
    </div>
</x-layouts.crm>