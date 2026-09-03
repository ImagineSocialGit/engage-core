<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Keep the service itself in one place, then jump to availability, follow-up, or advanced resources when needed."
>
    <div class="space-y-6" data-scheduling-service-editor="{{ $service->id }}">
        <datalist id="scheduling-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.services.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-service-editor-back
            >
                Back to Services
            </a>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                @if ($service->status === 'active')
                    <a
                        href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
                    >
                        Availability
                    </a>
                @endif

                <a
                    href="{{ route('crm.scheduling.configuration.after-booking.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                >
                    After Booking
                </a>
            </div>
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

        <x-ui.card class="space-y-4" data-scheduling-service-summary>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                            {{ str($service->status)->replace('_', ' ')->title() }}
                        </span>
                        @if ($service->is_public)
                            <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700">Public</span>
                        @endif
                        @if (! $serviceEditable)
                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">Managed externally</span>
                        @endif
                    </div>

                    @if ($service->description)
                        <p class="mt-3 max-w-3xl text-sm text-slate-600">{{ $service->description }}</p>
                    @endif
                </div>

                <dl class="grid min-w-0 grid-cols-2 gap-3 text-sm sm:min-w-80">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <dt class="text-slate-500">Appointments</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $service->appointments_count }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <dt class="text-slate-500">Assigned staff</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $service->active_host_assignments_count }}</dd>
                    </div>
                </dl>
            </div>

            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div class="text-slate-500">Appointment format</div>
                    <div class="font-medium text-slate-900">{{ $service->appointmentFormatLabel() ?? 'Not configured' }}</div>
                </div>
                <div>
                    <div class="text-slate-500">Method</div>
                    <div class="font-medium text-slate-900">{{ $service->appointmentMethodLabel() ?? 'Not configured' }}</div>
                </div>
                <div>
                    <div class="text-slate-500">Timezone</div>
                    <div class="font-medium text-slate-900">{{ $service->timezone }}</div>
                </div>
                <div>
                    <div class="text-slate-500">Availability rules</div>
                    <div class="font-medium text-slate-900">{{ $service->availability_windows_count }}</div>
                </div>
            </div>
        </x-ui.card>

        @if ($serviceEditable)
            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.services.update', $service) }}"
                class="space-y-6"
                data-configuration-service-update="{{ $service->id }}"
                x-data="{
                    appointmentFormat: @js(old('appointment_format', $appointmentConfiguration['appointment_format'] ?? '')),
                    inPersonArrangement: @js(old('in_person_arrangement', $appointmentConfiguration['in_person_arrangement'] ?? '')),
                    remoteMethod: @js(old('remote_method', $appointmentConfiguration['remote_method'] ?? '')),
                    durationMode: @js(old('duration_mode', $service->duration_mode ?? 'fixed')),
                    formatComplete() {
                        return (this.appointmentFormat === 'in_person' && ['business_location', 'customer_address'].includes(this.inPersonArrangement))
                            || (this.appointmentFormat === 'remote' && ['phone', 'virtual_meeting'].includes(this.remoteMethod));
                    }
                }"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">
                <input type="hidden" name="sort_order" value="{{ $service->sort_order }}">

                <x-ui.card class="space-y-5" data-scheduling-service-section="basics">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Service
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-900">Basics</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Name the appointment type and decide whether it is active, public, or awaiting staff confirmation.
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Name
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="name"
                                value="{{ old('name', $service->name) }}"
                                required
                            >
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            Status
                            <select
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="status"
                                required
                            >
                                @foreach ($serviceStatuses as $status)
                                    <option value="{{ $status }}" @selected(old('status', $service->status) === $status)>
                                        {{ str($status)->replace('_', ' ')->title() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                            Description <span class="font-normal text-slate-400">(optional)</span>
                            <textarea
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="description"
                                rows="3"
                            >{{ old('description', $service->description) }}</textarea>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                            <input type="hidden" name="requires_confirmation" value="0">
                            <input
                                class="mt-0.5"
                                type="checkbox"
                                name="requires_confirmation"
                                value="1"
                                @checked((bool) old('requires_confirmation', $service->requires_confirmation))
                            >
                            <span>
                                <span class="block font-semibold text-slate-900">Require staff confirmation</span>
                                <span class="mt-0.5 block text-slate-500">New appointments begin as awaiting confirmation.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                            <input type="hidden" name="is_public" value="0">
                            <input
                                class="mt-0.5"
                                type="checkbox"
                                name="is_public"
                                value="1"
                                @checked((bool) old('is_public', $service->is_public))
                                x-bind:disabled="!formatComplete()"
                            >
                            <span>
                                <span class="block font-semibold text-slate-900">Let customers book this themselves</span>
                                <span class="mt-0.5 block text-slate-500">A complete appointment format is required before a service can be public.</span>
                            </span>
                        </label>
                    </div>
                </x-ui.card>

                <x-ui.card class="space-y-5" data-scheduling-service-section="appointment">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Appointment
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-900">Length & appointment format</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Describe how long the appointment takes and how the customer will meet with the business.
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Booking length
                            <select
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="duration_mode"
                                x-model="durationMode"
                                required
                            >
                                <option value="fixed">One fixed length</option>
                                <option value="range">Flexible length / multi-day stay</option>
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            <span x-text="durationMode === 'range' ? 'Default booking length (minutes)' : 'Appointment length (minutes)'"></span>
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                type="number"
                                min="1"
                                x-bind:max="durationMode === 'range' ? {{ $maxRangeDurationMinutes }} : 1440"
                                name="duration_minutes"
                                value="{{ old('duration_minutes', $service->duration_minutes) }}"
                                required
                            >
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="durationMode === 'range'" x-cloak>
                            Shortest allowed booking (minutes)
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                type="number"
                                min="1"
                                max="{{ $maxRangeDurationMinutes }}"
                                name="minimum_duration_minutes"
                                value="{{ old('minimum_duration_minutes', $service->minimum_duration_minutes ?? $service->duration_minutes) }}"
                                x-bind:disabled="durationMode !== 'range'"
                                x-bind:required="durationMode === 'range'"
                            >
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="durationMode === 'range'" x-cloak>
                            Longest allowed booking (minutes)
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                type="number"
                                min="1"
                                max="{{ $maxRangeDurationMinutes }}"
                                name="maximum_duration_minutes"
                                value="{{ old('maximum_duration_minutes', $service->maximum_duration_minutes ?? $service->duration_minutes) }}"
                                x-bind:disabled="durationMode !== 'range'"
                                x-bind:required="durationMode === 'range'"
                            >
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            Appointment format
                            <select
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="appointment_format"
                                x-model="appointmentFormat"
                            >
                                <option value="">Not configured</option>
                                <option value="in_person">In person</option>
                                <option value="remote">Remote</option>
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="appointmentFormat === 'in_person'" x-cloak>
                            Where will you meet?
                            <select
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="in_person_arrangement"
                                x-model="inPersonArrangement"
                                x-bind:disabled="appointmentFormat !== 'in_person'"
                            >
                                <option value="">Choose one</option>
                                <option value="business_location">At a business location</option>
                                <option value="customer_address">At an address the customer provides</option>
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="appointmentFormat === 'remote'" x-cloak>
                            How will the appointment happen?
                            <select
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="remote_method"
                                x-model="remoteMethod"
                                x-bind:disabled="appointmentFormat !== 'remote'"
                            >
                                <option value="">Choose one</option>
                                <option value="phone">Phone call</option>
                                <option value="virtual_meeting">Virtual meeting</option>
                            </select>
                        </label>

                        <label
                            class="block text-sm font-medium text-slate-700"
                            x-show="appointmentFormat === 'in_person' && inPersonArrangement === 'business_location'"
                            x-cloak
                            data-service-business-location-name
                        >
                            Location name <span class="font-normal text-slate-400">(optional)</span>
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="location_label"
                                value="{{ old('location_label', $service->location_type === 'fixed' ? ($locationDetails['label'] ?? '') : '') }}"
                                placeholder="Main office"
                                x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                            >
                            <span class="mt-1 block text-xs font-normal text-slate-500">
                                Use this only when the physical location has a useful customer-facing name. The appointment type itself comes from the format and meeting method above.
                            </span>
                        </label>

                        <div
                            class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 md:col-span-2"
                            x-show="appointmentFormat === 'remote' && remoteMethod === 'phone'"
                            x-cloak
                        >
                            At the scheduled time, {{ config('client.name', 'the team') }} will call the phone number the customer provides.
                        </div>

                        <div
                            class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 md:col-span-2"
                            x-show="appointmentFormat === 'in_person' && inPersonArrangement === 'customer_address'"
                            x-cloak
                        >
                            The customer will provide the appointment address before available times are calculated.
                        </div>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2" x-show="appointmentFormat === 'remote' && remoteMethod === 'virtual_meeting'" x-cloak>
                            Meeting link <span class="font-normal text-slate-400">(optional)</span>
                            <input
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                type="url"
                                name="location_url"
                                value="{{ old('location_url', $locationDetails['url'] ?? '') }}"
                                placeholder="https://…"
                                x-bind:disabled="appointmentFormat !== 'remote' || remoteMethod !== 'virtual_meeting'"
                            >
                        </label>

                        <div
                            class="grid gap-4 md:col-span-2 md:grid-cols-2"
                            x-show="appointmentFormat === 'in_person' && inPersonArrangement === 'business_location'"
                            x-cloak
                        >
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                                Street address
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_address_line_1"
                                    value="{{ old('location_address_line_1', $locationAddress['address_line_1'] ?? '') }}"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                                Address line 2 <span class="font-normal text-slate-400">(optional)</span>
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_address_line_2"
                                    value="{{ old('location_address_line_2', $locationAddress['address_line_2'] ?? '') }}"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                City
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_city"
                                    value="{{ old('location_city', $locationAddress['city'] ?? '') }}"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                State / region
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_region"
                                    value="{{ old('location_region', $locationAddress['region'] ?? '') }}"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Postal code
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_postal_code"
                                    value="{{ old('location_postal_code', $locationAddress['postal_code'] ?? '') }}"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Country code
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="location_country"
                                    value="{{ old('location_country', $locationAddress['country'] ?? 'US') }}"
                                    maxlength="2"
                                    x-bind:disabled="appointmentFormat !== 'in_person' || inPersonArrangement !== 'business_location'"
                                >
                                <span class="mt-1 block text-xs font-normal text-slate-500">Use a two-letter country code.</span>
                            </label>
                        </div>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2" x-show="formatComplete()" x-cloak>
                            What should the person know before the appointment? <span class="font-normal text-slate-400">(optional)</span>
                            <textarea
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                name="location_instructions"
                                rows="3"
                                x-bind:disabled="!formatComplete()"
                            >{{ old('location_instructions', $locationDetails['instructions'] ?? '') }}</textarea>
                        </label>
                    </div>
                </x-ui.card>

                <x-ui.card class="space-y-4" data-scheduling-service-section="advanced_booking_rules">
                    <details>
                        <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                            Advanced booking rules
                        </summary>

                        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <label class="block text-sm font-medium text-slate-700">
                                Timezone
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    name="timezone"
                                    list="scheduling-timezones"
                                    value="{{ old('timezone', $service->timezone) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Start-time interval (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="1"
                                    max="1440"
                                    name="slot_interval_minutes"
                                    value="{{ old('slot_interval_minutes', $service->slot_interval_minutes) }}"
                                    required
                                >
                                <span class="mt-1 block text-xs font-normal text-slate-500">
                                    For example, 15 allows starts at 9:00, 9:15, 9:30, and so on when available.
                                </span>
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Simultaneous capacity
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    name="capacity"
                                    value="{{ old('capacity', $service->capacity) }}"
                                    required
                                >
                                <span class="mt-1 block text-xs font-normal text-slate-500">Use 1 for the normal case.</span>
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Buffer before (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="buffer_before_minutes"
                                    value="{{ old('buffer_before_minutes', $service->buffer_before_minutes) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Buffer after (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="buffer_after_minutes"
                                    value="{{ old('buffer_after_minutes', $service->buffer_after_minutes) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Minimum booking notice (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="minimum_notice_minutes"
                                    value="{{ old('minimum_notice_minutes', $service->minimum_notice_minutes) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Booking horizon (days)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="booking_horizon_days"
                                    value="{{ old('booking_horizon_days', $service->booking_horizon_days) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Cancellation notice (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="cancellation_notice_minutes"
                                    value="{{ old('cancellation_notice_minutes', $service->cancellation_notice_minutes) }}"
                                    required
                                >
                            </label>

                            <label class="block text-sm font-medium text-slate-700">
                                Reschedule notice (minutes)
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="0"
                                    name="reschedule_notice_minutes"
                                    value="{{ old('reschedule_notice_minutes', $service->reschedule_notice_minutes) }}"
                                    required
                                >
                            </label>
                        </div>
                    </details>
                </x-ui.card>

                <div class="sticky bottom-4 z-10 rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">
                            Save the service before changing availability or related setup.
                        </p>
                        <button
                            type="submit"
                            class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                        >
                            Save service
                        </button>
                    </div>
                </div>
            </form>
        @else
            <x-ui.card data-configuration-read-only="service">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    This service is managed by a provider or system integration. Its business settings are shown here, but they cannot be edited from this CRM surface.
                </div>
            </x-ui.card>
        @endif

        <x-ui.card class="space-y-5" data-scheduling-service-staff>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Staff & providers
                    </div>
                    <h2 class="mt-3 text-lg font-semibold text-slate-900">Who can handle this service?</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        Leave every person unselected when this service does not need explicit assignment.
                    </p>
                </div>

                <a
                    href="{{ route('crm.scheduling.configuration.staff.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                >
                    Manage staff
                </a>
            </div>

            @if ($serviceEditable)
                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.services.hosts.update', $service) }}"
                    class="space-y-3"
                    data-service-assignment-form="{{ $service->id }}"
                >
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                    @forelse ($assignmentRows as $row)
                        <div
                            class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_180px] sm:items-end"
                            data-assignment-host-id="{{ $row['id'] }}"
                        >
                            <div>
                                <input type="hidden" name="assignments[{{ $loop->index }}][scheduling_host_id]" value="{{ $row['id'] }}">
                                <input type="hidden" name="assignments[{{ $loop->index }}][is_active]" value="0">

                                <label class="inline-flex items-start gap-2 text-sm font-medium text-slate-900">
                                    <input
                                        type="checkbox"
                                        name="assignments[{{ $loop->index }}][is_active]"
                                        value="1"
                                        @checked((bool) old("assignments.{$loop->index}.is_active", $row['active']))
                                        @disabled(! $row['selectable'])
                                    >
                                    <span>
                                        {{ $row['name'] }}
                                        <span class="mt-0.5 block text-xs font-normal text-slate-500">
                                            {{ str($row['status'])->replace('_', ' ')->title() }} · {{ $row['timezone'] }}
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <label class="block text-sm font-medium text-slate-700">
                                Pairing capacity <span class="font-normal text-slate-400">(optional)</span>
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    name="assignments[{{ $loop->index }}][capacity_override]"
                                    value="{{ old("assignments.{$loop->index}.capacity_override", $row['capacity_override']) }}"
                                >
                                <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to use the normal service and staff limits.</span>
                            </label>

                            <input
                                type="hidden"
                                name="assignments[{{ $loop->index }}][sort_order]"
                                value="{{ $row['sort_order'] }}"
                            >
                        </div>
                    @empty
                        <div
                            class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
                            data-configuration-empty="assignment-hosts"
                        >
                            No staff or providers have been added. This service can remain unassigned.
                        </div>
                    @endforelse

                    <button
                        type="submit"
                        class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                    >
                        Save staff assignments
                    </button>
                </form>
            @else
                <p class="text-sm text-slate-500">
                    Staff assignment for this provider-managed service is read-only here.
                </p>
            @endif
        </x-ui.card>

        <section class="space-y-4" data-scheduling-service-related-setup>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Related setup
                </div>
                <h2 class="mt-3 text-lg font-semibold text-slate-900">Finish the rest where it belongs</h2>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @if ($service->status === 'active')
                    <a
                        href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                        class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                        data-scheduling-service-related="availability"
                    >
                        <div class="font-semibold text-slate-900">Availability</div>
                        <div class="mt-1 text-slate-500">Normal hours, exceptions, and live time testing.</div>
                    </a>
                @endif

                <a
                    href="{{ route('crm.scheduling.configuration.after-booking.index') }}"
                    class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                    data-scheduling-service-related="after_booking"
                >
                    <div class="font-semibold text-slate-900">After Booking</div>
                    <div class="mt-1 text-slate-500">Follow-up and automation after an appointment is scheduled.</div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.communications.index') }}"
                    class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                    data-scheduling-service-related="communications"
                >
                    <div class="font-semibold text-slate-900">Communications</div>
                    <div class="mt-1 text-slate-500">Confirmation and reminder behavior.</div>
                </a>

                <a
                    href="{{ route('crm.scheduling.configuration.resources.index') }}"
                    class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                    data-scheduling-service-related="resources"
                >
                    <div class="font-semibold text-slate-900">Resources</div>
                    <div class="mt-1 text-slate-500">Advanced rooms, equipment, and shared capacity.</div>
                </a>
            </div>
        </section>
    </div>
</x-layouts.crm>