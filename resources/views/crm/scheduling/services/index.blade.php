<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Manage the appointment types people can book."
>
    <div class="space-y-6" data-scheduling-services-workspace>
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

        <x-ui.card class="space-y-5" data-configuration-service-create>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    New service
                </div>
                <h2 class="mt-3 text-lg font-semibold text-slate-900">Add something people can schedule</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">
                    Start with the name and normal appointment length. The dedicated edit page handles public booking, appointment format, staff assignment, and uncommon booking rules.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.services.store') }}"
                class="grid gap-4 md:grid-cols-2"
            >
                @csrf

                <label class="block text-sm font-medium text-slate-700">
                    Service name
                    <input
                        class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        name="name"
                        value="{{ old('name') }}"
                        placeholder="Consultation"
                        required
                    >
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Appointment length (minutes)
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
                        placeholder="What should a customer know about this appointment?"
                    >{{ old('description') }}</textarea>
                </label>

                <div class="md:col-span-2">
                    <button
                        type="submit"
                        class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto"
                    >
                        Add service
                    </button>
                </div>
            </form>
        </x-ui.card>

        <section class="space-y-4" data-scheduling-services-list>
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Services
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                        Existing services
                    </h2>
                </div>
                <div class="text-sm text-slate-500">{{ $services->count() }} total</div>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($services as $service)
                    <x-ui.card class="space-y-4">
                        <div
                            data-bookable-service-id="{{ $service->id }}"
                            data-crm-editable="{{ $service->getAttribute('crm_editable') ? '1' : '0' }}"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <h3 class="truncate text-lg font-semibold text-slate-900">{{ $service->name }}</h3>
                                    @if ($service->description)
                                        <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ $service->description }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ str($service->status)->replace('_', ' ')->title() }}
                                    </span>
                                    @if ($service->is_public)
                                        <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700">
                                            Public
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt class="text-slate-500">Length</dt>
                                    <dd class="font-medium text-slate-900">
                                        @if ($service->usesRangeDuration())
                                            {{ $service->minimumDurationMinutes() }}–{{ $service->maximumDurationMinutes() }} min
                                        @else
                                            {{ $service->duration_minutes }} min
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Method</dt>
                                    <dd class="font-medium text-slate-900">{{ $service->appointmentMethodLabel() ?? 'Not configured' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Staff</dt>
                                    <dd class="font-medium text-slate-900">{{ $service->active_host_assignments_count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Appointments</dt>
                                    <dd class="font-medium text-slate-900">{{ $service->appointments_count }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                <a
                                    href="{{ route('crm.scheduling.configuration.services.edit', $service) }}"
                                    class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                                    data-scheduling-service-edit="{{ $service->id }}"
                                >
                                    {{ $service->getAttribute('crm_editable') ? 'Edit service' : 'View service' }}
                                </a>

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
                    </x-ui.card>
                @empty
                    <x-ui.card class="xl:col-span-2">
                        <div
                            class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500"
                            data-configuration-empty="services"
                        >
                            No services are configured yet.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>