<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose what people can schedule, who can handle appointments, and when appointments can happen."
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-configuration-back
            >
                Back to Scheduling
            </a>

            <p class="text-sm text-slate-500">
                Start with a service. Scheduling fills in the rest with safe defaults.
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

        <datalist id="scheduling-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        <section class="space-y-5" id="services" data-configuration-section="services">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Services
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    What can people schedule?
                </h2>
            </div>

            <x-ui.card class="space-y-5">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Add something people can schedule</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Start with the basics. Scheduling will fill in sensible defaults, and you can change advanced booking rules later.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.services.store') }}"
                    class="grid gap-4 md:grid-cols-2"
                    data-configuration-service-create
                    x-data="{ locationType: @js(old('location_type', '')) }"
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        What can people schedule?
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" placeholder="30-minute consultation" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Appointment length (minutes)
                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="duration_minutes" value="{{ old('duration_minutes', 60) }}" required>
                    </label>

                    <label class="{{ $labelClass }} md:col-span-2">
                        Description <span class="font-normal text-slate-400">(optional)</span>
                        <textarea class="{{ $inputClass }}" name="description" rows="2" placeholder="What should a customer or staff member know about this appointment?">{{ old('description') }}</textarea>
                    </label>

                    <label class="{{ $labelClass }}">
                        Where does it happen?
                        <select class="{{ $inputClass }}" name="location_type" x-model="locationType">
                            <option value="">Decide later</option>
                            <option value="{{ \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_PHONE }}" @selected(old('location_type') === \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_PHONE)>Phone</option>
                            <option value="{{ \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_VIRTUAL }}" @selected(old('location_type') === \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_VIRTUAL)>Online / virtual</option>
                            <option value="{{ \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_FIXED }}" @selected(old('location_type') === \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_FIXED)>A fixed location</option>
                            <option value="{{ \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_CUSTOMER_SITE }}" @selected(old('location_type') === \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_CUSTOMER_SITE)>At the customer’s location</option>
                        </select>
                    </label>

                    <label class="{{ $labelClass }}" x-show="locationType !== ''" x-cloak>
                        Location name <span class="font-normal text-slate-400">(optional)</span>
                        <input class="{{ $inputClass }}" name="location_label" value="{{ old('location_label') }}" placeholder="Main office" x-bind:disabled="locationType === ''">
                    </label>

                    <label class="{{ $labelClass }} md:col-span-2" x-show="locationType === 'virtual'" x-cloak>
                        Meeting link <span class="font-normal text-slate-400">(optional)</span>
                        <input class="{{ $inputClass }}" type="url" name="location_url" value="{{ old('location_url') }}" placeholder="https://…" x-bind:disabled="locationType !== 'virtual'">
                    </label>

                    <div class="grid gap-4 md:col-span-2 md:grid-cols-2" x-show="locationType === 'fixed'" x-cloak>
                        <label class="{{ $labelClass }} md:col-span-2">
                            Street address
                            <input class="{{ $inputClass }}" name="location_address_line_1" value="{{ old('location_address_line_1') }}" x-bind:disabled="locationType !== 'fixed'">
                        </label>
                        <label class="{{ $labelClass }} md:col-span-2">
                            Address line 2 <span class="font-normal text-slate-400">(optional)</span>
                            <input class="{{ $inputClass }}" name="location_address_line_2" value="{{ old('location_address_line_2') }}" x-bind:disabled="locationType !== 'fixed'">
                        </label>
                        <label class="{{ $labelClass }}">
                            City
                            <input class="{{ $inputClass }}" name="location_city" value="{{ old('location_city') }}" x-bind:disabled="locationType !== 'fixed'">
                        </label>
                        <label class="{{ $labelClass }}">
                            State / region
                            <input class="{{ $inputClass }}" name="location_region" value="{{ old('location_region') }}" x-bind:disabled="locationType !== 'fixed'">
                        </label>
                        <label class="{{ $labelClass }}">
                            Postal code
                            <input class="{{ $inputClass }}" name="location_postal_code" value="{{ old('location_postal_code') }}" x-bind:disabled="locationType !== 'fixed'">
                        </label>
                        <label class="{{ $labelClass }}">
                            Country code
                            <input class="{{ $inputClass }}" name="location_country" value="{{ old('location_country', 'US') }}" maxlength="2" x-bind:disabled="locationType !== 'fixed'">
                            <span class="mt-1 block text-xs font-normal text-slate-500">
                                Use the two-letter country code, such as US or CA.
                            </span>
                        </label>
                    </div>

                    <label class="{{ $labelClass }} md:col-span-2" x-show="locationType !== ''" x-cloak>
                        Location instructions <span class="font-normal text-slate-400">(optional)</span>
                        <textarea class="{{ $inputClass }}" name="location_instructions" rows="2" x-bind:disabled="locationType === ''">{{ old('location_instructions') }}</textarea>
                    </label>

                    <p class="text-sm text-slate-500 md:col-span-2" x-show="locationType === 'customer_site'" x-cloak>
                        The customer will provide the address when the appointment is booked.
                    </p>

                    <div class="space-y-3 md:col-span-2">
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm text-slate-700">
                            <input type="hidden" name="is_public" value="0">
                            <input class="mt-0.5" type="checkbox" name="is_public" value="1" @checked(old('is_public'))>
                            <span>
                                <span class="block font-semibold text-slate-900">Let customers book this themselves</span>
                                <span class="mt-0.5 block text-slate-500">Makes this service eligible for the public booking page when public Scheduling is enabled.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm text-slate-700">
                            <input type="hidden" name="requires_confirmation" value="0">
                            <input class="mt-0.5" type="checkbox" name="requires_confirmation" value="1" @checked(old('requires_confirmation'))>
                            <span>
                                <span class="block font-semibold text-slate-900">Require staff confirmation</span>
                                <span class="mt-0.5 block text-slate-500">New appointments start as awaiting confirmation instead of being immediately scheduled.</span>
                            </span>
                        </label>
                    </div>

                    <div class="md:col-span-2">
                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                            Add service
                        </button>
                        <p class="mt-2 text-xs text-slate-500">
                            Need a multi-day stay, variable-length booking, extra time between appointments, multiple appointments at once, or another uncommon rule? Add the service first, then open Advanced service settings.
                        </p>
                    </div>
                </form>
            </x-ui.card>

            <div class="space-y-4">
                @forelse ($services as $service)
                    @php
                        $serviceEditable = (bool) $service->getAttribute('crm_editable');
                        $assignmentByHost = $service->hostAssignments->keyBy('scheduling_host_id');
                        $locationDetails = is_array($service->location_details) ? $service->location_details : [];
                        $locationAddress = is_array($locationDetails['address'] ?? null) ? $locationDetails['address'] : [];
                    @endphp

                    <div
                        data-bookable-service-id="{{ $service->id }}"
                        data-crm-editable="{{ $serviceEditable ? '1' : '0' }}"
                    >
                        <x-ui.card class="space-y-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $service->name }}</h3>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($service->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt class="text-slate-500">Assigned people</dt>
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
                                <dt class="text-slate-500">Availability rules</dt>
                                <dd class="font-medium text-slate-900">{{ $service->availability_windows_count }}</dd>
                            </div>
                        </dl>

                        @if ($serviceEditable)
                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                    Advanced service settings
                                </summary>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.services.update', $service) }}"
                                    class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                                    data-configuration-service-update="{{ $service->id }}"
                                    x-data="{ locationType: @js($service->location_type ?? ''), durationMode: @js($service->duration_mode ?? 'fixed') }"
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
                                        Booking length
                                        <select class="{{ $inputClass }}" name="duration_mode" x-model="durationMode" required>
                                            <option value="fixed">One fixed length</option>
                                            <option value="range">Flexible length / multi-day stay</option>
                                        </select>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        <span x-text="durationMode === 'range' ? 'Default booking length (minutes)' : 'Appointment length (minutes)'"></span>
                                        <input class="{{ $inputClass }}" type="number" min="1" x-bind:max="durationMode === 'range' ? {{ \App\Modules\Scheduling\Models\BookableService::MAX_RANGE_DURATION_MINUTES }} : 1440" name="duration_minutes" value="{{ $service->duration_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}" x-show="durationMode === 'range'" x-cloak>
                                        Shortest allowed booking (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="1" max="{{ \App\Modules\Scheduling\Models\BookableService::MAX_RANGE_DURATION_MINUTES }}" name="minimum_duration_minutes" value="{{ $service->minimum_duration_minutes ?? $service->duration_minutes }}" x-bind:disabled="durationMode !== 'range'" x-bind:required="durationMode === 'range'">
                                    </label>

                                    <label class="{{ $labelClass }}" x-show="durationMode === 'range'" x-cloak>
                                        Longest allowed booking (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="1" max="{{ \App\Modules\Scheduling\Models\BookableService::MAX_RANGE_DURATION_MINUTES }}" name="maximum_duration_minutes" value="{{ $service->maximum_duration_minutes ?? $service->duration_minutes }}" x-bind:disabled="durationMode !== 'range'" x-bind:required="durationMode === 'range'">
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        How often should start times be offered? (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="1" max="1440" name="slot_interval_minutes" value="{{ $service->slot_interval_minutes }}" required>
                                        <span class="mt-1 block text-xs font-normal text-slate-500">
                                            For example, 15 offers starts at 9:00, 9:15, 9:30, and so on when those times are available.
                                        </span>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        How many of these appointments can happen at the same time?
                                        <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $service->capacity }}" required>
                                        <span class="mt-1 block text-xs font-normal text-slate-500">
                                            Use 1 unless this service is designed to allow more than one appointment at the same time. Other staff and availability limits still apply.
                                        </span>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Extra time kept free before each appointment (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_before_minutes" value="{{ $service->buffer_before_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Extra time kept free after each appointment (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="buffer_after_minutes" value="{{ $service->buffer_after_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Minimum advance notice before booking (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="minimum_notice_minutes" value="{{ $service->minimum_notice_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        How far ahead can people book? (days)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="booking_horizon_days" value="{{ $service->booking_horizon_days }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Required notice to cancel (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="cancellation_notice_minutes" value="{{ $service->cancellation_notice_minutes }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Required notice to reschedule (minutes)
                                        <input class="{{ $inputClass }}" type="number" min="0" name="reschedule_notice_minutes" value="{{ $service->reschedule_notice_minutes }}" required>
                                    </label>

                                    <input type="hidden" name="sort_order" value="{{ $service->sort_order }}">

                                    <label class="{{ $labelClass }}">
                                        Location type
                                        <select class="{{ $inputClass }}" name="location_type" x-model="locationType">
                                            <option value="">Not specified</option>
                                            <option value="phone">Phone</option>
                                            <option value="virtual">Virtual</option>
                                            <option value="fixed">Fixed location</option>
                                            <option value="customer_site">Customer site</option>
                                        </select>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Location label
                                        <input class="{{ $inputClass }}" name="location_label" value="{{ $locationDetails['label'] ?? '' }}" x-bind:disabled="locationType === ''">
                                    </label>

                                    <label class="{{ $labelClass }} md:col-span-2 xl:col-span-4">
                                        Location instructions
                                        <textarea class="{{ $inputClass }}" name="location_instructions" rows="2" x-bind:disabled="locationType === ''">{{ $locationDetails['instructions'] ?? '' }}</textarea>
                                    </label>

                                    <label class="{{ $labelClass }} md:col-span-2 xl:col-span-4" x-show="locationType === 'virtual'" x-cloak>
                                        Virtual meeting URL
                                        <input class="{{ $inputClass }}" type="url" name="location_url" value="{{ $locationDetails['url'] ?? '' }}" x-bind:disabled="locationType !== 'virtual'">
                                    </label>

                                    <div class="grid gap-4 md:col-span-2 md:grid-cols-2 xl:col-span-4 xl:grid-cols-4" x-show="locationType === 'fixed'" x-cloak>
                                        <label class="{{ $labelClass }} md:col-span-2">
                                            Address line 1
                                            <input class="{{ $inputClass }}" name="location_address_line_1" value="{{ $locationAddress['address_line_1'] ?? '' }}" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                        <label class="{{ $labelClass }} md:col-span-2">
                                            Address line 2
                                            <input class="{{ $inputClass }}" name="location_address_line_2" value="{{ $locationAddress['address_line_2'] ?? '' }}" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                        <label class="{{ $labelClass }}">
                                            City
                                            <input class="{{ $inputClass }}" name="location_city" value="{{ $locationAddress['city'] ?? '' }}" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                        <label class="{{ $labelClass }}">
                                            State / region
                                            <input class="{{ $inputClass }}" name="location_region" value="{{ $locationAddress['region'] ?? '' }}" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                        <label class="{{ $labelClass }}">
                                            Postal code
                                            <input class="{{ $inputClass }}" name="location_postal_code" value="{{ $locationAddress['postal_code'] ?? '' }}" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                        <label class="{{ $labelClass }}">
                                            Country code
                                            <input class="{{ $inputClass }}" name="location_country" value="{{ $locationAddress['country'] ?? 'US' }}" maxlength="2" x-bind:disabled="locationType !== 'fixed'">
                                        </label>
                                    </div>

                                    <p class="text-xs text-slate-500 md:col-span-2 xl:col-span-4" x-show="locationType === 'customer_site'" x-cloak>
                                        Customer-site services collect the service address from each booking. Only the optional label and instructions are stored on the service.
                                    </p>

                                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:gap-5 md:col-span-2 xl:col-span-4">
                                        <label class="inline-flex items-start gap-2 text-sm font-medium text-slate-700">
                                            <input type="hidden" name="requires_confirmation" value="0">
                                            <input type="checkbox" name="requires_confirmation" value="1" @checked($service->requires_confirmation)>
                                            Requires confirmation
                                        </label>

                                        <label class="inline-flex items-start gap-2 text-sm font-medium text-slate-700">
                                            <input type="hidden" name="is_public" value="0">
                                            <input type="checkbox" name="is_public" value="1" @checked($service->is_public)>
                                            Publicly bookable
                                        </label>
                                    </div>

                                    <div class="md:col-span-2 xl:col-span-4">
                                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">
                                            Save service
                                        </button>
                                    </div>
                                </form>
                            </details>

                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                    Choose who can handle this
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
                                                <label class="inline-flex items-start gap-2 text-sm font-medium text-slate-900">
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
                                                    {{ str($host->status)->replace("_", " ")->title() }} · {{ $host->timezone }}
                                                </p>
                                            </div>

                                            <label class="{{ $labelClass }}">
                                                Maximum simultaneous appointments for this pairing <span class="font-normal text-slate-400">(optional)</span>
                                                <input
                                                    class="{{ $inputClass }}"
                                                    type="number"
                                                    min="1"
                                                    max="100000"
                                                    name="assignments[{{ $loop->index }}][capacity_override]"
                                                    value="{{ $assignment?->capacity_override }}"
                                                >
                                                <span class="mt-1 block text-xs font-normal text-slate-500">
                                                    Leave blank to use the normal service and staff limits.
                                                </span>
                                            </label>

                                            <input
                                                type="hidden"
                                                name="assignments[{{ $loop->index }}][sort_order]"
                                                value="{{ $assignment?->sort_order ?? $host->sort_order }}"
                                            >
                                        </div>
                                    @empty
                                        <div data-configuration-empty="assignment-hosts" class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">
                                            Add a staff member or provider before assigning this service.
                                        </div>
                                    @endforelse

                                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">
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

        <section class="space-y-5" id="people" data-configuration-section="hosts">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    People
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Who can handle appointments?
                </h2>
            </div>

            <x-ui.card class="space-y-5">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Add staff or a provider</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        This step is optional. Add someone here when appointments need to be assigned to a specific person or provider.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.hosts.store') }}"
                    class="grid gap-4 md:grid-cols-3"
                    data-configuration-host-create
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        Name
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" placeholder="Taylor Smith" required>
                    </label>

                    <label class="{{ $labelClass }}">
                        Email <span class="font-normal text-slate-400">(optional)</span>
                        <input class="{{ $inputClass }}" type="email" name="email" value="{{ old('email') }}">
                    </label>

                    <label class="{{ $labelClass }}">
                        Phone <span class="font-normal text-slate-400">(optional)</span>
                        <input class="{{ $inputClass }}" name="phone" value="{{ old('phone') }}">
                    </label>

                    <div class="md:col-span-3">
                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                            Add staff or provider
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
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $host->name }}</h3>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($host->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-slate-500">Services</dt>
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
                                <dt class="text-slate-500">Availability rules</dt>
                                <dd class="font-medium text-slate-900">{{ $host->availability_windows_count }}</dd>
                            </div>
                        </dl>

                        @if ($hostEditable)
                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                    Advanced staff / provider settings
                                </summary>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.hosts.update', $host) }}"
                                    class="mt-4 grid gap-4 sm:grid-cols-2"
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
                                    How many appointments can this person handle at the same time?
                                    <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $host->capacity }}" required>
                                    <span class="mt-1 block text-xs font-normal text-slate-500">
                                        Use 1 for the normal case. Increase it only when this person can genuinely handle multiple appointments at once.
                                    </span>
                                </label>

                                <label class="{{ $labelClass }}">
                                    Email
                                    <input class="{{ $inputClass }}" type="email" name="email" value="{{ $host->email }}">
                                </label>

                                <label class="{{ $labelClass }}">
                                    Phone
                                    <input class="{{ $inputClass }}" name="phone" value="{{ $host->phone }}">
                                </label>

                                <input type="hidden" name="sort_order" value="{{ $host->sort_order }}">

                                <div class="self-end">
                                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">
                                        Save changes
                                    </button>
                                </div>
                            </form>
                            </details>
                        @else
                            <div class="{{ $readOnlyClass }}" data-configuration-read-only="host">
                                <p class="text-sm text-slate-600">
                                    This person is managed automatically and cannot be edited here.
                                </p>
                            </div>
                        @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-configuration-empty="hosts" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No staff or providers have been added. That is fine when appointments do not need a specific assignee.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>


        <section id="availability" class="space-y-4">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Hours & availability
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    When can appointments happen?
                </h2>
            </div>

            <x-ui.card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between" data-scheduling-availability-configuration-link>
                    <div>
                        <p class="font-semibold text-slate-900">Set normal hours and exceptions</p>
                        <p class="mt-1 text-sm text-slate-500">
                            Tell Scheduling when appointments are normally available and when they should be blocked for exceptions.
                        </p>
                    </div>

                    <a
                        href="{{ route('crm.scheduling.configuration.availability.index') }}"
                        class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                    >
                        Set availability
                    </a>
                </div>
            </x-ui.card>
        </section>

        <details class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-scheduling-resource-configuration-link>
            <summary class="cursor-pointer text-sm font-semibold text-slate-800">
                Advanced: rooms, equipment, and shared capacity
            </summary>
            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-500">
                    Use Resources only when a booking depends on limited rooms, equipment, or another shared item. Most businesses do not need this for basic Scheduling setup.
                </p>
                <a
                    href="{{ route('crm.scheduling.configuration.resources.index') }}"
                    class="inline-flex w-full justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                >
                    Manage advanced resources
                </a>
            </div>
        </details>
    </div>
</x-layouts.crm>