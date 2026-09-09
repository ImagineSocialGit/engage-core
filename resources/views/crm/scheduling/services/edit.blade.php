<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Finish one appointment type in order. Required steps stay visible, while recommended and advanced options explain when they matter."
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

            <div
                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                @if ($service->getAttribute('public_booking_ready'))
                    x-data="{ copied: false }"
                @endif
            >
                @if ($service->getAttribute('public_booking_ready'))
                    <input
                        x-ref="bookingLink"
                        class="sr-only"
                        type="text"
                        value="{{ $service->getAttribute('public_booking_url') }}"
                        readonly
                        tabindex="-1"
                        aria-hidden="true"
                    >
                    <a
                        href="{{ $service->getAttribute('public_booking_url') }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                        data-scheduling-service-preview
                    >
                        Preview booking page
                    </a>
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
                        data-scheduling-service-copy-link
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
                @endif

                @if ($service->status === 'active')
                    <a
                        href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                    >
                        Availability
                    </a>
                @endif
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

                <dl class="grid min-w-0 grid-cols-2 gap-3 text-sm sm:min-w-96">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <dt class="text-slate-500">Hosts</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $service->getAttribute('active_host_summary') }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <dt class="text-slate-500">Next appointment</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $service->getAttribute('next_appointment_label') ?? 'None scheduled' }}</dd>
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

        @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])

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
                <input type="hidden" name="slot_interval_minutes" value="{{ old('slot_interval_minutes', $service->slot_interval_minutes) }}">
                <input type="hidden" name="buffer_before_minutes" value="{{ old('buffer_before_minutes', $service->buffer_before_minutes) }}">
                <input type="hidden" name="buffer_after_minutes" value="{{ old('buffer_after_minutes', $service->buffer_after_minutes) }}">

                <x-ui.card id="basics" class="scroll-mt-6 space-y-5" data-scheduling-service-section="basics">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Appointment type
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
                                <span class="mt-0.5 block text-slate-500">A complete appointment format is required before an appointment type can be public.</span>
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
                            Other booking rules
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
                            Save the appointment type before changing availability or related setup.
                        </p>
                        <button
                            type="submit"
                            class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                        >
                            Save appointment type
                        </button>
                    </div>
                </div>
            </form>
        @else
            <x-ui.card data-configuration-read-only="service">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    This appointment type is managed by a provider or system integration. Its business settings are shown here, but they cannot be edited from this CRM surface.
                </div>
            </x-ui.card>
        @endif

        <x-ui.card id="staff" class="scroll-mt-6 space-y-5" data-scheduling-service-staff>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Staff & providers
                        </div>
                        <span @class([
                            'rounded-full px-2 py-1 text-xs font-semibold',
                            'bg-orange-100 text-orange-900' => $setupProgress['staff_guidance']['recommended'],
                            'bg-slate-100 text-slate-700' => ! $setupProgress['staff_guidance']['recommended'],
                        ])>
                            {{ $setupProgress['staff_guidance']['label'] }}
                        </span>
                    </div>
                    <h2 class="mt-3 text-lg font-semibold text-slate-900">Who can handle this appointment type?</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        {{ $setupProgress['staff_guidance']['description'] }}
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
                                <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to use the normal appointment-type and staff limits.</span>
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
                            No staff or providers have been added. This appointment type can remain unassigned.
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
                    Staff assignment for this provider-managed appointment type is read-only here.
                </p>
            @endif
        </x-ui.card>

        <section class="space-y-4" data-scheduling-service-related-setup>
            <x-ui.card
                id="availability"
                class="scroll-mt-6 space-y-4"
                data-scheduling-service-related="availability"
                data-scheduling-service-workflow-step="availability"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">2. Availability</div>
                        <h2 class="mt-2 text-lg font-semibold text-slate-900">When can people book this?</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            Set normal hours, date-specific changes, start-time spacing, preparation time, and time to leave free afterward.
                        </p>
                    </div>
                    @if ($service->status === 'active')
                        <a
                            href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                            class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50 sm:w-auto"
                        >
                            Set hours & booking timing
                        </a>
                    @endif
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <span class="text-slate-600">Saved availability rules:</span>
                    <span class="font-semibold text-slate-900">{{ $service->availability_windows_count }}</span>
                </div>
            </x-ui.card>

            <x-ui.card
                id="booking-form"
                class="scroll-mt-6 space-y-4"
                data-scheduling-service-workflow-step="booking_form"
                data-scheduling-booking-form-summary
            >
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">3. Booking form</div>
                    <h2 class="mt-2 text-lg font-semibold text-slate-900">What will the person be asked?</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        The public booking form stays short: first name, last name, email, and phone. Phone is required for phone appointments.
                    </p>
                </div>

                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="font-semibold text-slate-900">Contact details</div>
                        <div class="mt-1 text-slate-600">First name, last name, email, and phone.</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="font-semibold text-slate-900">Appointment details</div>
                        <div class="mt-1 text-slate-600">
                            @if (($appointmentConfiguration['location_type'] ?? null) === 'customer_site')
                                The appointment address is collected before available times are shown.
                            @else
                                The meeting method and preparation details come from Basics above.
                            @endif
                        </div>
                    </div>
                </div>

                @if ($service->getAttribute('public_booking_ready'))
                    <a
                        href="{{ $service->getAttribute('public_booking_url') }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                    >
                        Preview booking form
                    </a>
                @else
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                        {{ $service->getAttribute('public_booking_issue') }}
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card
                id="communications"
                class="scroll-mt-6 space-y-4"
                data-scheduling-service-related="communications"
                data-scheduling-service-workflow-step="communications"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">4. Confirmation & reminders</div>
                        <h2 class="mt-2 text-lg font-semibold text-slate-900">Keep people on track after they book</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            Appointment confirmations and reminders use one shared schedule across appointment types, so you only maintain the timing and message once.
                        </p>
                    </div>
                    <a
                        href="{{ route('crm.scheduling.configuration.communications.index') }}"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                    >
                        Manage messages
                    </a>
                </div>
            </x-ui.card>

            <x-ui.card
                id="after-booking"
                class="scroll-mt-6 space-y-4"
                data-scheduling-service-related="after_booking"
                data-scheduling-service-workflow-step="after_booking"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">5. After booking</div>
                        <h2 class="mt-2 text-lg font-semibold text-slate-900">What should happen next?</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            Send this appointment type into its follow-up path, or use the simple fallback actions available in this setup.
                        </p>
                    </div>
                    <a
                        href="{{ route('crm.scheduling.configuration.after-booking.index') }}"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                    >
                        Manage after booking
                    </a>
                </div>
            </x-ui.card>

            <x-ui.card
                id="advanced"
                class="scroll-mt-6 space-y-4"
                data-scheduling-service-workflow-step="advanced"
            >
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">6. Advanced</div>
                    <h2 class="mt-2 text-lg font-semibold text-slate-900">Only open these when the appointment needs them</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        Shared rooms, equipment, staff-directory maintenance, and CRM access stay out of the normal appointment-type setup.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <a
                        href="{{ route('crm.scheduling.configuration.resources.index') }}"
                        class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                        data-scheduling-service-related="resources"
                    >
                        <div class="font-semibold text-slate-900">Rooms, equipment & shared capacity</div>
                        <div class="mt-1 text-slate-500">Use this only when appointments compete for something limited, such as one conference room, two courts, a specialized machine, or a small vehicle fleet.</div>
                    </a>

                    <a
                        href="{{ route('crm.scheduling.configuration.staff.index') }}"
                        class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                        data-scheduling-service-related="staff"
                    >
                        <div class="font-semibold text-slate-900">Appointment hosts</div>
                        <div class="mt-1 text-slate-500">Maintain the people and providers that can receive appointments.</div>
                    </a>

                    <a
                        href="{{ route('crm.settings.team.index') }}"
                        class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm hover:border-teal-300"
                        data-scheduling-service-related="team_access"
                    >
                        <div class="font-semibold text-slate-900">Team access</div>
                        <div class="mt-1 text-slate-500">Control who can sign in to the CRM and what they can access.</div>
                    </a>
                </div>
            </x-ui.card>
        </section>

    </div>
</x-layouts.crm>