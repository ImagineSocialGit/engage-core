<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Edit the essential appointment details in one focused workspace. Availability and staff are managed separately."
>
    <div class="space-y-6" data-scheduling-service-details="{{ $service->id }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.services.edit', $service) }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Back to appointment type
            </a>
            <a
                href="{{ route('crm.scheduling.configuration.availability.index', ['service_id' => $service->id]) }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
            >
                Availability
            </a>
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

        @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])

        <datalist id="scheduling-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        @if ($serviceEditable)
            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.services.update', $service) }}"
                class="space-y-6"
                data-configuration-service-details-update="{{ $service->id }}"
                x-data="{
                    appointmentMethod: @js(old('appointment_method', $appointmentMethodKey ?? '')),
                    durationMode: @js(old('duration_mode', $service->duration_mode ?? 'fixed')),
                    publicBooking: @js((bool) old('is_public', $service->is_public)),
                    publicSurfaceReady: @js($publicSurfaceReady),
                    methodComplete() {
                        return ['phone', 'virtual_meeting', 'business_location', 'customer_address'].includes(this.appointmentMethod);
                    }
                }"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                <x-ui.card class="space-y-5" data-scheduling-service-details-section="basics">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Appointment details</div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-900">What is being booked?</h2>
                        <p class="mt-1 text-sm text-slate-500">Name the appointment, set its normal length, and choose how it happens. These are the core details Scheduling needs before availability can be useful.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Name
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="name" value="{{ old('name', $service->name) }}" required>
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            Status
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="status" required>
                                @foreach ($serviceStatuses as $status)
                                    <option value="{{ $status }}" @selected(old('status', $service->status) === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                            Description <span class="font-normal text-slate-400">(optional)</span>
                            <textarea class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="description" rows="3">{{ old('description', $service->description) }}</textarea>
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            Booking length
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="duration_mode" x-model="durationMode" required>
                                <option value="fixed">One fixed length</option>
                                <option value="range">Flexible length / multi-day stay</option>
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            <span x-text="durationMode === 'range' ? 'Default booking length (minutes)' : 'Appointment length (minutes)'"></span>
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="1" x-bind:max="durationMode === 'range' ? {{ $maxRangeDurationMinutes }} : 1440" name="duration_minutes" value="{{ old('duration_minutes', $service->duration_minutes) }}" required>
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="durationMode === 'range'" x-cloak>
                            Shortest allowed booking (minutes)
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="1" max="{{ $maxRangeDurationMinutes }}" name="minimum_duration_minutes" value="{{ old('minimum_duration_minutes', $service->minimum_duration_minutes ?? $service->duration_minutes) }}" x-bind:disabled="durationMode !== 'range'" x-bind:required="durationMode === 'range'">
                        </label>

                        <label class="block text-sm font-medium text-slate-700" x-show="durationMode === 'range'" x-cloak>
                            Longest allowed booking (minutes)
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="1" max="{{ $maxRangeDurationMinutes }}" name="maximum_duration_minutes" value="{{ old('maximum_duration_minutes', $service->maximum_duration_minutes ?? $service->duration_minutes) }}" x-bind:disabled="durationMode !== 'range'" x-bind:required="durationMode === 'range'">
                        </label>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                            Appointment format
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="appointment_method" x-model="appointmentMethod" required>
                                <option value="">Choose how this appointment happens</option>
                                <option value="phone">Phone call</option>
                                <option value="virtual_meeting">Virtual meeting</option>
                                <option value="business_location">At a business location</option>
                                <option value="customer_address">At an address the customer provides</option>
                            </select>
                            <span class="mt-1 block text-xs font-normal text-slate-500">This is required before the appointment type can be considered ready for booking.</span>
                            <x-ui.form.error name="appointment_method" />
                        </label>

                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 md:col-span-2" x-show="appointmentMethod === 'phone'" x-cloak>
                            At the scheduled time, {{ config('client.name', 'the team') }} will call the phone number provided for the appointment.
                        </div>

                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 md:col-span-2" x-show="appointmentMethod === 'customer_address'" x-cloak>
                            The customer will provide the appointment address before available times are calculated.
                        </div>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2" x-show="appointmentMethod === 'virtual_meeting'" x-cloak>
                            Meeting link <span class="font-normal text-slate-400">(optional)</span>
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="url" name="location_url" value="{{ old('location_url', $locationDetails['url'] ?? '') }}" placeholder="https://…" x-bind:disabled="appointmentMethod !== 'virtual_meeting'">
                        </label>

                        <div class="grid gap-4 md:col-span-2 md:grid-cols-2" x-show="appointmentMethod === 'business_location'" x-cloak>
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                                Location name <span class="font-normal text-slate-400">(optional)</span>
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_label" value="{{ old('location_label', $locationDetails['label'] ?? '') }}" placeholder="Main office" x-bind:disabled="appointmentMethod !== 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                                Street address
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_address_line_1" value="{{ old('location_address_line_1', $locationAddress['address_line_1'] ?? '') }}" x-bind:disabled="appointmentMethod !== 'business_location'" x-bind:required="appointmentMethod === 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">
                                Address line 2 <span class="font-normal text-slate-400">(optional)</span>
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_address_line_2" value="{{ old('location_address_line_2', $locationAddress['address_line_2'] ?? '') }}" x-bind:disabled="appointmentMethod !== 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                City
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_city" value="{{ old('location_city', $locationAddress['city'] ?? '') }}" x-bind:disabled="appointmentMethod !== 'business_location'" x-bind:required="appointmentMethod === 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                State / region
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_region" value="{{ old('location_region', $locationAddress['region'] ?? '') }}" x-bind:disabled="appointmentMethod !== 'business_location'" x-bind:required="appointmentMethod === 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Postal code
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_postal_code" value="{{ old('location_postal_code', $locationAddress['postal_code'] ?? '') }}" x-bind:disabled="appointmentMethod !== 'business_location'" x-bind:required="appointmentMethod === 'business_location'">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Country code
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_country" value="{{ old('location_country', $locationAddress['country'] ?? 'US') }}" maxlength="2" x-bind:disabled="appointmentMethod !== 'business_location'" x-bind:required="appointmentMethod === 'business_location'">
                                <span class="mt-1 block text-xs font-normal text-slate-500">Use a two-letter country code.</span>
                            </label>
                        </div>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2" x-show="methodComplete()" x-cloak>
                            Preparation / meeting instructions <span class="font-normal text-slate-400">(optional)</span>
                            <textarea class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_instructions" rows="3" x-bind:disabled="!methodComplete() || !publicSurfaceReady">{{ old('location_instructions', $locationDetails['instructions'] ?? '') }}</textarea>
                        </label>
                    </div>
                </x-ui.card>

                <x-ui.card id="public-booking" class="scroll-mt-6 space-y-5" data-scheduling-service-details-section="booking">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Booking behavior</div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-900">How should this appointment type be used?</h2>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                            <input type="hidden" name="requires_confirmation" value="0">
                            <input class="mt-0.5" type="checkbox" name="requires_confirmation" value="1" @checked((bool) old('requires_confirmation', $service->requires_confirmation))>
                            <span>
                                <span class="block font-semibold text-slate-900">Require staff confirmation</span>
                                <span class="mt-0.5 block text-slate-500">New appointments begin as awaiting confirmation.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700" x-bind:class="!methodComplete() || !publicSurfaceReady ? 'opacity-60' : ''">
                            <input type="hidden" name="is_public" value="0">
                            <input class="mt-0.5" type="checkbox" name="is_public" value="1" x-model="publicBooking" x-bind:disabled="!methodComplete() || !publicSurfaceReady">
                            <span>
                                <span class="block font-semibold text-slate-900">Let customers book this themselves</span>
                                <span class="mt-0.5 block text-slate-500" x-show="methodComplete() && publicSurfaceReady">Turn this on when customers should receive a public booking link.</span>
                                <span class="mt-0.5 block font-semibold text-amber-700" x-show="!methodComplete()">Choose the appointment format first.</span>
                                <span class="mt-0.5 block font-semibold text-amber-700" x-show="methodComplete() && !publicSurfaceReady">Public booking is unavailable until the public Scheduling URL is configured for this environment.</span>
                            </span>
                        </label>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" x-show="methodComplete() && !publicSurfaceReady" x-cloak>
                        <span class="font-semibold">Public booking unavailable.</span> Configure the public Scheduling URL for this environment before customer self-booking can be turned on.
                    </div>
                </x-ui.card>

                <x-ui.card class="space-y-4" data-scheduling-service-details-section="advanced">
                    <details>
                        <summary class="cursor-pointer text-sm font-semibold text-teal-700">Other booking rules</summary>
                        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <label class="block text-sm font-medium text-slate-700">
                                Timezone
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="timezone" list="scheduling-timezones" value="{{ old('timezone', $service->timezone) }}" required>
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Simultaneous capacity
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="1" max="100000" name="capacity" value="{{ old('capacity', $service->capacity) }}" required>
                                <span class="mt-1 block text-xs font-normal text-slate-500">Use 1 for the normal case.</span>
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Minimum booking notice (minutes)
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="0" name="minimum_notice_minutes" value="{{ old('minimum_notice_minutes', $service->minimum_notice_minutes) }}" required>
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Booking horizon (days)
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="0" name="booking_horizon_days" value="{{ old('booking_horizon_days', $service->booking_horizon_days) }}" required>
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Cancellation notice (minutes)
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="0" name="cancellation_notice_minutes" value="{{ old('cancellation_notice_minutes', $service->cancellation_notice_minutes) }}" required>
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Reschedule notice (minutes)
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="0" name="reschedule_notice_minutes" value="{{ old('reschedule_notice_minutes', $service->reschedule_notice_minutes) }}" required>
                            </label>
                        </div>
                    </details>
                </x-ui.card>

                <div class="sticky bottom-4 z-10 rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Save these details, then manage availability or staff from their focused workspaces.</p>
                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">Save appointment details</button>
                    </div>
                </div>
            </form>
        @else
            <x-ui.card data-configuration-read-only="service">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">This appointment type is managed by a provider or system integration. Its business settings are visible here but cannot be edited from this CRM surface.</div>
            </x-ui.card>
        @endif
    </div>
</x-layouts.crm>