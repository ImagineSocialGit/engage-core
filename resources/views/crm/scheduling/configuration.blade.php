<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Manage the durable hosts, services, and host assignments used by Scheduling."
>
    @php
        $hostStatuses = [
            \App\Modules\Scheduling\Models\SchedulingHost::STATUS_ACTIVE,
            \App\Modules\Scheduling\Models\SchedulingHost::STATUS_INACTIVE,
            \App\Modules\Scheduling\Models\SchedulingHost::STATUS_ARCHIVED,
        ];
        $serviceStatuses = [
            \App\Modules\Scheduling\Models\BookableService::STATUS_ACTIVE,
            \App\Modules\Scheduling\Models\BookableService::STATUS_INACTIVE,
            \App\Modules\Scheduling\Models\BookableService::STATUS_ARCHIVED,
        ];
        $inputClass = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200';
        $labelClass = 'block text-sm font-medium text-slate-700';
        $readOnlyClass = 'rounded-xl border border-slate-200 bg-slate-50 p-4';
    @endphp

    <div class="space-y-6" data-scheduling-configuration>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ route('crm.scheduling.index') }}"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                data-scheduling-configuration-back
            >
                Back to Scheduling
            </a>

            <p class="text-sm text-slate-500">
                Keys and source ownership are immutable after creation.
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

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hosts</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-configuration-host-count="{{ $hosts->count() }}">
                    {{ $hosts->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active hosts</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $hosts->where('status', \App\Modules\Scheduling\Models\SchedulingHost::STATUS_ACTIVE)->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Services</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-configuration-service-count="{{ $services->count() }}">
                    {{ $services->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Public services</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $services->where('status', \App\Modules\Scheduling\Models\BookableService::STATUS_ACTIVE)->where('is_public', true)->count() }}
                </p>
            </x-ui.card>
        </div>

        <datalist id="scheduling-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        <section class="space-y-5" data-configuration-section="hosts">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Hosts
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Scheduling hosts
                </h2>
            </div>

            <x-ui.card class="space-y-4">
                <h3 class="font-semibold text-slate-900">Create a manual host</h3>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.hosts.store') }}"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    data-configuration-host-create
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        Key
                        <input class="{{ $inputClass }}" name="key" value="{{ old('key') }}" required pattern="[a-z0-9]+(?:[-_][a-z0-9]+)*">
                    </label>

                    <label class="{{ $labelClass }}">
                        Name
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Status
                        <select class="{{ $inputClass }}" name="status" required>
                            @foreach ($hostStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', 'active') === $status)>
                                    {{ str($status)->replace('_', ' ')->title() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="{{ $labelClass }}">
                        Timezone
                        <input class="{{ $inputClass }}" name="timezone" list="scheduling-timezones" value="{{ old('timezone', $defaultTimezone) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Capacity
                        <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ old('capacity', 1) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Email
                        <input class="{{ $inputClass }}" type="email" name="email" value="{{ old('email') }}">
                    </label>

                    <label class="{{ $labelClass }}">
                        Phone
                        <input class="{{ $inputClass }}" name="phone" value="{{ old('phone') }}">
                    </label>

                    <label class="{{ $labelClass }}">
                        Sort order
                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ old('sort_order', 0) }}" required>
                    </label>

                    <div class="md:col-span-2 xl:col-span-4">
                        <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                            Create host
                        </button>
                    </div>
                </form>
            </x-ui.card>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($hosts as $host)
                    @php
                        $hostEditable = (bool) $host->getAttribute('crm_editable');
                    @endphp

                    <div
                        data-scheduling-host-id="{{ $host->id }}"
                        data-crm-editable="{{ $hostEditable ? '1' : '0' }}"
                    >
                        <x-ui.card class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $host->name }}</h3>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $host->key }}</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($host->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div>
                                <dt class="text-slate-500">Source</dt>
                                <dd class="font-medium text-slate-900">{{ $host->source }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Assignments</dt>
                                <dd class="font-medium text-slate-900" data-active-assignment-count="{{ $host->active_service_assignments_count }}">
                                    {{ $host->active_service_assignments_count }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Appointments</dt>
                                <dd class="font-medium text-slate-900" data-appointment-count="{{ $host->appointments_count }}">
                                    {{ $host->appointments_count }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Windows</dt>
                                <dd class="font-medium text-slate-900">{{ $host->availability_windows_count }}</dd>
                            </div>
                        </dl>

                        @if ($hostEditable)
                            <form
                                method="POST"
                                action="{{ route('crm.scheduling.configuration.hosts.update', $host) }}"
                                class="grid gap-4 sm:grid-cols-2"
                                data-configuration-host-update="{{ $host->id }}"
                            >
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="current_version" value="{{ $host->updated_at?->toISOString() }}">

                                <label class="{{ $labelClass }}">
                                    Name
                                    <input class="{{ $inputClass }}" name="name" value="{{ $host->name }}" required>
                                </label>

                                <label class="{{ $labelClass }}">
                                    Status
                                    <select class="{{ $inputClass }}" name="status" required>
                                        @foreach ($hostStatuses as $status)
                                            <option value="{{ $status }}" @selected($host->status === $status)>
                                                {{ str($status)->replace('_', ' ')->title() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="{{ $labelClass }}">
                                    Timezone
                                    <input class="{{ $inputClass }}" name="timezone" list="scheduling-timezones" value="{{ $host->timezone }}" required>
                                </label>

                                <label class="{{ $labelClass }}">
                                    Capacity
                                    <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $host->capacity }}" required>
                                </label>

                                <label class="{{ $labelClass }}">
                                    Email
                                    <input class="{{ $inputClass }}" type="email" name="email" value="{{ $host->email }}">
                                </label>

                                <label class="{{ $labelClass }}">
                                    Phone
                                    <input class="{{ $inputClass }}" name="phone" value="{{ $host->phone }}">
                                </label>

                                <label class="{{ $labelClass }}">
                                    Sort order
                                    <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ $host->sort_order }}" required>
                                </label>

                                <div class="self-end">
                                    <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                        Save host
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="{{ $readOnlyClass }}" data-configuration-read-only="host">
                                <p class="text-sm text-slate-600">
                                    This host is owned by a provider, the system, or another model and is read-only here.
                                </p>
                            </div>
                        @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-configuration-empty="hosts" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No hosts are configured.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-configuration-section="services">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Services
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Bookable services
                </h2>
            </div>

            <x-ui.card class="space-y-4">
                <h3 class="font-semibold text-slate-900">Create a manual service</h3>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.services.store') }}"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    data-configuration-service-create
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        Key
                        <input class="{{ $inputClass }}" name="key" value="{{ old('key') }}" required pattern="[a-z0-9]+(?:[-_][a-z0-9]+)*">
                    </label>

                    <label class="{{ $labelClass }}">
                        Name
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" required>
                    </label>

                    <label class="{{ $labelClass }} md:col-span-2">
                        Description
                        <textarea class="{{ $inputClass }}" name="description" rows="2">{{ old('description') }}</textarea>
                    </label>

                    <label class="{{ $labelClass }}">
                        Status
                        <select class="{{ $inputClass }}" name="status" required>
                            @foreach ($serviceStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', 'active') === $status)>
                                    {{ str($status)->replace('_', ' ')->title() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="{{ $labelClass }}">
                        Timezone
                        <input class="{{ $inputClass }}" name="timezone" list="scheduling-timezones" value="{{ old('timezone', $defaultTimezone) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Duration minutes
                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="duration_minutes" value="{{ old('duration_minutes', 60) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Slot interval minutes
                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="slot_interval_minutes" value="{{ old('slot_interval_minutes', 15) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Buffer before
                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_before_minutes" value="{{ old('buffer_before_minutes', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Buffer after
                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_after_minutes" value="{{ old('buffer_after_minutes', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Minimum notice minutes
                        <input class="{{ $inputClass }}" type="number" min="0" name="minimum_notice_minutes" value="{{ old('minimum_notice_minutes', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Booking horizon days
                        <input class="{{ $inputClass }}" type="number" min="0" name="booking_horizon_days" value="{{ old('booking_horizon_days', 60) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Cancellation notice minutes
                        <input class="{{ $inputClass }}" type="number" min="0" name="cancellation_notice_minutes" value="{{ old('cancellation_notice_minutes', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Reschedule notice minutes
                        <input class="{{ $inputClass }}" type="number" min="0" name="reschedule_notice_minutes" value="{{ old('reschedule_notice_minutes', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Capacity
                        <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ old('capacity', 1) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Sort order
                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ old('sort_order', 0) }}" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Location type
                        <input class="{{ $inputClass }}" name="location_type" value="{{ old('location_type') }}">
                    </label>

                    <label class="{{ $labelClass }}">
                        Location label
                        <input class="{{ $inputClass }}" name="location_label" value="{{ old('location_label') }}">
                    </label>

                    <label class="{{ $labelClass }} md:col-span-2">
                        Location URL
                        <input class="{{ $inputClass }}" type="url" name="location_url" value="{{ old('location_url') }}">
                    </label>

                    <div class="flex flex-wrap gap-5 md:col-span-2 xl:col-span-4">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="hidden" name="requires_confirmation" value="0">
                            <input type="checkbox" name="requires_confirmation" value="1" @checked(old('requires_confirmation'))>
                            Requires confirmation
                        </label>

                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="hidden" name="is_public" value="0">
                            <input type="checkbox" name="is_public" value="1" @checked(old('is_public'))>
                            Publicly bookable
                        </label>
                    </div>

                    <div class="md:col-span-2 xl:col-span-4">
                        <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                            Create service
                        </button>
                    </div>
                </form>
            </x-ui.card>

            <div class="space-y-4">
                @forelse ($services as $service)
                    @php
                        $serviceEditable = (bool) $service->getAttribute('crm_editable');
                        $assignmentByHost = $service->hostAssignments->keyBy('scheduling_host_id');
                        $locationDetails = is_array($service->location_details) ? $service->location_details : [];
                    @endphp

                    <div
                        data-bookable-service-id="{{ $service->id }}"
                        data-crm-editable="{{ $serviceEditable ? '1' : '0' }}"
                    >
                        <x-ui.card class="space-y-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $service->name }}</h3>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $service->key }}</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($service->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                            <div>
                                <dt class="text-slate-500">Source</dt>
                                <dd class="font-medium text-slate-900">{{ $service->source }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Active hosts</dt>
                                <dd class="font-medium text-slate-900" data-active-host-count="{{ $service->active_host_assignments_count }}">
                                    {{ $service->active_host_assignments_count }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Assignments</dt>
                                <dd class="font-medium text-slate-900">{{ $service->host_assignments_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Appointments</dt>
                                <dd class="font-medium text-slate-900" data-appointment-count="{{ $service->appointments_count }}">
                                    {{ $service->appointments_count }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Windows</dt>
                                <dd class="font-medium text-slate-900">{{ $service->availability_windows_count }}</dd>
                            </div>
                        </dl>

                        @if ($serviceEditable)
                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                    Edit service policy
                                </summary>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.services.update', $service) }}"
                                    class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                                    data-configuration-service-update="{{ $service->id }}"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                                    <label class="{{ $labelClass }}">
                                        Name
                                        <input class="{{ $inputClass }}" name="name" value="{{ $service->name }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Status
                                        <select class="{{ $inputClass }}" name="status" required>
                                            @foreach ($serviceStatuses as $status)
                                                <option value="{{ $status }}" @selected($service->status === $status)>
                                                    {{ str($status)->replace('_', ' ')->title() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="{{ $labelClass }} md:col-span-2">
                                        Description
                                        <textarea class="{{ $inputClass }}" name="description" rows="2">{{ $service->description }}</textarea>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Timezone
                                        <input class="{{ $inputClass }}" name="timezone" list="scheduling-timezones" value="{{ $service->timezone }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Duration minutes
                                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="duration_minutes" value="{{ $service->duration_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Slot interval minutes
                                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="slot_interval_minutes" value="{{ $service->slot_interval_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Capacity
                                        <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $service->capacity }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Buffer before
                                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_before_minutes" value="{{ $service->buffer_before_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Buffer after
                                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_after_minutes" value="{{ $service->buffer_after_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Minimum notice minutes
                                        <input class="{{ $inputClass }}" type="number" min="0" name="minimum_notice_minutes" value="{{ $service->minimum_notice_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Booking horizon days
                                        <input class="{{ $inputClass }}" type="number" min="0" name="booking_horizon_days" value="{{ $service->booking_horizon_days }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Cancellation notice minutes
                                        <input class="{{ $inputClass }}" type="number" min="0" name="cancellation_notice_minutes" value="{{ $service->cancellation_notice_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Reschedule notice minutes
                                        <input class="{{ $inputClass }}" type="number" min="0" name="reschedule_notice_minutes" value="{{ $service->reschedule_notice_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Sort order
                                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ $service->sort_order }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Location type
                                        <input class="{{ $inputClass }}" name="location_type" value="{{ $service->location_type }}">
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Location label
                                        <input class="{{ $inputClass }}" name="location_label" value="{{ $locationDetails['label'] ?? '' }}">
                                    </label>

                                    <label class="{{ $labelClass }} md:col-span-2">
                                        Location URL
                                        <input class="{{ $inputClass }}" type="url" name="location_url" value="{{ $locationDetails['url'] ?? '' }}">
                                    </label>

                                    <div class="flex flex-wrap gap-5 md:col-span-2 xl:col-span-4">
                                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="hidden" name="requires_confirmation" value="0">
                                            <input type="checkbox" name="requires_confirmation" value="1" @checked($service->requires_confirmation)>
                                            Requires confirmation
                                        </label>

                                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="hidden" name="is_public" value="0">
                                            <input type="checkbox" name="is_public" value="1" @checked($service->is_public)>
                                            Publicly bookable
                                        </label>
                                    </div>

                                    <div class="md:col-span-2 xl:col-span-4">
                                        <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                            Save service
                                        </button>
                                    </div>
                                </form>
                            </details>

                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                    Manage host assignments
                                </summary>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.services.hosts.update', $service) }}"
                                    class="mt-4 space-y-3"
                                    data-service-assignment-form="{{ $service->id }}"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                                    @forelse ($hosts as $host)
                                        @php
                                            $assignment = $assignmentByHost->get($host->id);
                                            $assignmentActive = (bool) $assignment?->is_active;
                                        @endphp

                                        <div
                                            class="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_140px_120px] sm:items-end"
                                            data-assignment-host-id="{{ $host->id }}"
                                        >
                                            <div>
                                                <input type="hidden" name="assignments[{{ $loop->index }}][scheduling_host_id]" value="{{ $host->id }}">
                                                <input type="hidden" name="assignments[{{ $loop->index }}][is_active]" value="0">
                                                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-900">
                                                    <input
                                                        type="checkbox"
                                                        name="assignments[{{ $loop->index }}][is_active]"
                                                        value="1"
                                                        @checked($assignmentActive)
                                                        @disabled($host->status !== \App\Modules\Scheduling\Models\SchedulingHost::STATUS_ACTIVE && ! $assignmentActive)
                                                    >
                                                    {{ $host->name }}
                                                </label>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ $host->status }} · {{ $host->timezone }} · capacity {{ $host->capacity }}
                                                </p>
                                            </div>

                                            <label class="{{ $labelClass }}">
                                                Capacity override
                                                <input
                                                    class="{{ $inputClass }}"
                                                    type="number"
                                                    min="1"
                                                    max="100000"
                                                    name="assignments[{{ $loop->index }}][capacity_override]"
                                                    value="{{ $assignment?->capacity_override }}"
                                                >
                                            </label>

                                            <label class="{{ $labelClass }}">
                                                Sort order
                                                <input
                                                    class="{{ $inputClass }}"
                                                    type="number"
                                                    min="0"
                                                    max="100000"
                                                    name="assignments[{{ $loop->index }}][sort_order]"
                                                    value="{{ $assignment?->sort_order ?? $host->sort_order }}"
                                                    required
                                                >
                                            </label>
                                        </div>
                                    @empty
                                        <div data-configuration-empty="assignment-hosts" class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">
                                            Create a host before assigning this service.
                                        </div>
                                    @endforelse

                                    <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                        Save assignments
                                    </button>
                                </form>
                            </details>
                        @else
                            <div class="{{ $readOnlyClass }}" data-configuration-read-only="service">
                                <p class="text-sm text-slate-600">
                                    This service has provider or system ownership and is read-only here.
                                </p>
                            </div>
                        @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-configuration-empty="services" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No services are configured.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>