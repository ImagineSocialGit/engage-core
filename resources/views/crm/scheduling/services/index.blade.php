<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Create the appointment types people can book, then open one to finish availability, reminders, follow-up, and advanced setup."
>
    <div class="space-y-6" data-scheduling-services-workspace data-scheduling-appointment-type-workspace>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-services-back
            >
                Back to Scheduling Setup
            </a>

            <a
                href="{{ route('crm.scheduling.configuration.availability.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
            >
                Manage availability
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

        <x-ui.card class="space-y-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-3xl">
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Appointment types
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                        Start with what someone wants to book
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">
                        Each appointment type keeps its length, meeting method, hosts, availability, public booking link, and follow-up path together.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm sm:min-w-72">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="text-slate-500">Appointment types</div>
                        <div class="mt-1 text-xl font-semibold text-slate-900">{{ $services->count() }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="text-slate-500">Shareable now</div>
                        <div class="mt-1 text-xl font-semibold text-slate-900">
                            {{ $shareableServiceCount }}
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <section class="space-y-4" data-scheduling-services-list>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight text-slate-900">
                        Your appointment types
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Open one to work through its setup from start to finish.
                    </p>
                </div>
                <div class="text-sm text-slate-500">{{ $services->count() }} total</div>
            </div>

            <div class="space-y-4">
                @forelse ($services as $service)
                    <x-ui.card class="space-y-5">
                        <div
                            data-bookable-service-id="{{ $service->id }}"
                            data-scheduling-appointment-type-card="{{ $service->id }}"
                            data-crm-editable="{{ $service->getAttribute('crm_editable') ? '1' : '0' }}"
                        >
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-lg font-semibold text-slate-900">{{ $service->name }}</h3>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                            {{ str($service->status)->replace('_', ' ')->title() }}
                                        </span>
                                        @if ($service->is_public)
                                            <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700">
                                                Public
                                            </span>
                                        @endif
                                        @if (! $service->getAttribute('crm_editable'))
                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                                Managed externally
                                            </span>
                                        @endif
                                    </div>

                                    @if ($service->description)
                                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ $service->description }}</p>
                                    @endif
                                </div>

                                <a
                                    href="{{ route('crm.scheduling.configuration.services.edit', $service) }}"
                                    class="inline-flex w-full shrink-0 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                                    data-scheduling-service-edit="{{ $service->id }}"
                                >
                                    Open
                                </a>
                            </div>

                            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
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
                                    <dt class="text-slate-500">Meeting method</dt>
                                    <dd class="mt-1 font-semibold text-slate-900">
                                        {{ $service->appointmentMethodLabel() ?? 'Not configured' }}
                                    </dd>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3" data-scheduling-host-summary>
                                    <dt class="text-slate-500">Hosts</dt>
                                    <dd class="mt-1 font-semibold text-slate-900">
                                        {{ $service->getAttribute('active_host_summary') }}
                                    </dd>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3" data-scheduling-next-appointment>
                                    <dt class="text-slate-500">Next appointment</dt>
                                    <dd class="mt-1 font-semibold text-slate-900">
                                        {{ $service->getAttribute('next_appointment_label') ?? 'None scheduled' }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-5 grid gap-4 border-t border-slate-100 pt-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                                @if ($service->getAttribute('public_booking_ready'))
                                    <div
                                        class="min-w-0"
                                        data-scheduling-booking-link="{{ $service->id }}"
                                        x-data="{ copied: false }"
                                    >
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Booking link
                                        </div>
                                        <input
                                            x-ref="bookingLink"
                                            class="mt-1 block w-full truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700"
                                            value="{{ $service->getAttribute('public_booking_url') }}"
                                            readonly
                                            aria-label="Public booking link for {{ $service->name }}"
                                        >
                                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                            <a
                                                href="{{ $service->getAttribute('public_booking_url') }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                                            >
                                                Preview
                                            </a>
                                            <button
                                                type="button"
                                                class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50 sm:w-auto"
                                                x-on:click="
                                                    if (navigator.clipboard) {
                                                        navigator.clipboard.writeText($refs.bookingLink.value);
                                                    } else {
                                                        $refs.bookingLink.select();
                                                        document.execCommand('copy');
                                                    }
                                                    copied = true;
                                                    setTimeout(() => copied = false, 1600);
                                                "
                                            >
                                                <span x-text="copied ? 'Copied' : 'Copy booking link'">Copy booking link</span>
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                        <div class="font-semibold text-slate-900">Not shareable yet</div>
                                        <div class="mt-1">{{ $service->getAttribute('public_booking_issue') }}</div>
                                    </div>
                                @endif

                                <div class="flex flex-col gap-2 sm:flex-row lg:justify-end">
                                    @if ($service->status === \App\Modules\Scheduling\Models\BookableService::STATUS_ACTIVE)
                                        <a
                                            href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                                            class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                                        >
                                            Availability
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <div
                            class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500"
                            data-configuration-empty="services"
                        >
                            No appointment types are configured yet. Add the first one below.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <x-ui.card class="space-y-5" data-configuration-service-create>
            <details @if ($services->isEmpty() || $errors->any()) open @endif>
                <summary class="cursor-pointer list-none">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                                New appointment type
                            </div>
                            <h2 class="mt-3 text-lg font-semibold text-slate-900">Add something people can schedule</h2>
                            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                                Start with a name and normal length. Open it afterward to choose how it happens, who handles it, when it can be booked, and what happens next.
                            </p>
                        </div>
                        <span class="text-sm font-semibold text-teal-700">Open form</span>
                    </div>
                </summary>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.services.store') }}"
                    class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-2"
                >
                    @csrf

                    <label class="block text-sm font-medium text-slate-700">
                        Appointment type name
                        <input
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="Consultation"
                            required
                        >
                    </label>

                    <label class="block text-sm font-medium text-slate-700">
                        Normal length (minutes)
                        <input
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                            type="number"
                            min="1"
                            max="1440"
                            name="duration_minutes"
                            value="{{ old('duration_minutes', 60) }}"
                            required
                        >
                    </label>

                    <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                        Description <span class="font-normal text-slate-400">(optional)</span>
                        <textarea
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                            name="description"
                            rows="2"
                            placeholder="What should someone know about this appointment?"
                        >{{ old('description') }}</textarea>
                    </label>

                    <div class="md:col-span-2">
                        <button
                            type="submit"
                            class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto"
                        >
                            Add appointment type
                        </button>
                    </div>
                </form>
            </details>
        </x-ui.card>
    </div>
</x-layouts.crm>