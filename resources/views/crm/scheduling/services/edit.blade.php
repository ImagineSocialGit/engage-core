<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review booking readiness at a glance, then open only the part of this appointment type you need to change."
>
    <div class="space-y-6" data-scheduling-service-editor="{{ $service->id }}" data-scheduling-service-hub>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.services.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-service-editor-back
            >
                Back to appointment types
            </a>

            @if ($readiness['internal_ready'])
                <a
                    href="{{ route('crm.scheduling.appointments.create', ['bookable_service_id' => $service->id]) }}"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                >
                    Schedule an appointment
                </a>
            @endif
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
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

        <x-ui.card class="space-y-5" data-scheduling-service-readiness>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Appointment type</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ str($service->status)->replace('_', ' ')->title() }}</span>
                        @if (! $serviceEditable)
                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">Managed externally</span>
                        @endif
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">{{ $service->name }}</h2>
                    @if ($service->description)
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ $service->description }}</p>
                    @endif
                </div>

                <div class="grid gap-2 text-sm sm:grid-cols-2 lg:min-w-80 lg:grid-cols-1">
                    <div @class([
                        'rounded-xl border p-3',
                        'border-emerald-200 bg-emerald-50' => $readiness['internal_ready'],
                        'border-amber-200 bg-amber-50' => ! $readiness['internal_ready'],
                    ])>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Internal booking</div>
                        <div @class([
                            'mt-1 font-semibold',
                            'text-emerald-800' => $readiness['internal_ready'],
                            'text-amber-900' => ! $readiness['internal_ready'],
                        ])>{{ $readiness['internal_ready'] ? 'Ready to schedule' : 'Needs setup' }}</div>
                    </div>
                    <div @class([
                        'rounded-xl border p-3',
                        'border-emerald-200 bg-emerald-50' => $readiness['public_ready'],
                        'border-slate-200 bg-slate-50' => ! $readiness['self_booking_enabled'],
                        'border-amber-200 bg-amber-50' => $readiness['self_booking_enabled'] && ! $readiness['public_ready'],
                    ])>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Public booking</div>
                        <div class="mt-1 font-semibold text-slate-900">
                            @if ($readiness['public_ready'])
                                Shareable
                            @elseif (! $readiness['self_booking_enabled'])
                                Customer self-booking off
                            @else
                                Not shareable yet
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-slate-500">Length</dt>
                    <dd class="mt-1 font-semibold text-slate-900">
                        @if ($service->usesRangeDuration())
                            {{ $service->minimumDurationMinutes() }}–{{ $service->maximumDurationMinutes() }} min
                        @else
                            {{ $service->duration_minutes }} min
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-slate-500">Appointment format</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $service->appointmentMethodLabel() ?? 'Missing' }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-slate-500">Availability</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $readiness['has_availability'] ? 'Configured' : 'Missing' }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-slate-500">Staff</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $service->getAttribute('active_host_summary') }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])

        @if ($readiness['internal_blockers'] !== [])
            <x-ui.card class="space-y-4 border-amber-200 bg-amber-50" data-scheduling-internal-blockers>
                <div>
                    <h2 class="text-lg font-semibold text-amber-950">Needs attention before this can be booked</h2>
                    <p class="mt-1 text-sm text-amber-900">These are actual booking prerequisites, not simply the next configuration screen.</p>
                </div>
                <div class="space-y-3">
                    @foreach ($readiness['internal_blockers'] as $blocker)
                        <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between" data-scheduling-readiness-blocker="{{ $blocker['key'] }}">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $blocker['label'] }}</div>
                                <p class="mt-1 text-sm text-slate-600">{{ $blocker['description'] }}</p>
                            </div>
                            <a href="{{ $blocker['url'] }}" class="inline-flex w-full shrink-0 justify-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">{{ $blocker['action_label'] }}</a>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        <x-ui.card id="public-booking" class="scroll-mt-6 space-y-4" data-scheduling-public-booking-status>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Public booking</div>
                    <h2 class="mt-3 text-lg font-semibold text-slate-900">Can customers book this themselves?</h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">A shareable link only appears when the appointment type itself is ready, customer self-booking is enabled, and the public Scheduling URL is configured.</p>
                </div>
                <a href="{{ route('crm.scheduling.configuration.services.details.edit', $service) }}#public-booking" class="inline-flex w-full shrink-0 justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto">Edit booking settings</a>
            </div>

            @if ($readiness['public_ready'])
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4" x-data="{ copied: false }" data-scheduling-booking-link="{{ $service->id }}">
                    <div class="text-sm font-semibold text-emerald-900">Shareable now</div>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <input x-ref="bookingLink" class="min-w-0 flex-1 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm text-slate-700" value="{{ $readiness['public_url'] }}" readonly aria-label="Public booking link for {{ $service->name }}">
                        <a href="{{ $readiness['public_url'] }}" target="_blank" rel="noopener" class="inline-flex justify-center rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">Preview</a>
                        <button type="button" class="inline-flex justify-center rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-900" x-on:click="navigator.clipboard?.writeText($refs.bookingLink.value); copied = true; setTimeout(() => copied = false, 1600)"><span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span></button>
                    </div>
                </div>
            @elseif ($readiness['public_blockers'] !== [])
                <div class="space-y-3">
                    @foreach ($readiness['public_blockers'] as $blocker)
                        <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between" data-scheduling-public-blocker="{{ $blocker['key'] }}">
                            <div>
                                <div class="font-semibold text-amber-950">{{ $blocker['label'] }}</div>
                                <p class="mt-1 text-sm text-amber-900">{{ $blocker['description'] }}</p>
                            </div>
                            <a href="{{ $blocker['url'] }}" class="inline-flex w-full shrink-0 justify-center rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 sm:w-auto">{{ $blocker['action_label'] }}</a>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <section class="space-y-4" data-scheduling-service-configuration-links>
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-slate-900">Configuration</h2>
                <p class="mt-1 text-sm text-slate-500">Open a focused workspace instead of editing the entire appointment type on one long page.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <a href="{{ route('crm.scheduling.configuration.services.details.edit', $service) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="details">
                    <div class="flex items-center justify-between gap-3"><span class="font-semibold text-slate-900">Appointment details</span><span class="text-xs font-semibold {{ $readiness['format_complete'] && $readiness['active'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $readiness['format_complete'] && $readiness['active'] ? 'Complete' : 'Needs attention' }}</span></div>
                    <p class="mt-2 text-sm text-slate-500">Name, length, format, location/method, public-booking intent, and booking rules.</p>
                </a>

                <a href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="availability">
                    <div class="flex items-center justify-between gap-3"><span class="font-semibold text-slate-900">Availability</span><span class="text-xs font-semibold {{ $readiness['has_availability'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $readiness['has_availability'] ? 'Configured' : 'Required' }}</span></div>
                    <p class="mt-2 text-sm text-slate-500">Regular hours, start-time spacing, buffers, special hours, and time off.</p>
                </a>

                <a href="{{ route('crm.scheduling.configuration.services.staff.edit', $service) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="staff">
                    <div class="flex items-center justify-between gap-3"><span class="font-semibold text-slate-900">Staff & providers</span><span class="text-xs font-semibold text-slate-500">{{ $readiness['staff_required'] ? ($readiness['has_active_staff'] ? 'Complete' : 'Required') : $setupProgress['staff_guidance']['label'] }}</span></div>
                    <p class="mt-2 text-sm text-slate-500">Choose which people can handle this appointment type when person-specific assignment matters.</p>
                </a>

                <a href="{{ route('crm.scheduling.configuration.communications.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="communications">
                    <div class="font-semibold text-slate-900">Confirmation & reminders</div>
                    <p class="mt-2 text-sm text-slate-500">Manage the shared appointment confirmation and reminder schedule.</p>
                </a>

                <a href="{{ route('crm.scheduling.configuration.after-booking.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="after-booking">
                    <div class="font-semibold text-slate-900">After booking</div>
                    <p class="mt-2 text-sm text-slate-500">Choose status, task, tags, or the enabled automation path after a booking is created.</p>
                </a>

                <a href="{{ route('crm.scheduling.configuration.resources.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-300" data-scheduling-config-card="resources">
                    <div class="font-semibold text-slate-900">Rooms, equipment & shared capacity</div>
                    <p class="mt-2 text-sm text-slate-500">Use this only when appointments compete for a limited room, court, vehicle, machine, or other shared resource.</p>
                </a>
            </div>
        </section>
    </div>
</x-layouts.crm>