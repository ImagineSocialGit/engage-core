<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Create what people can book. The first screen captures the minimum details needed before Availability becomes the real next step."
>
    <div class="space-y-6" data-scheduling-services-workspace data-scheduling-appointment-type-workspace>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('crm.scheduling.configuration.index') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto" data-scheduling-services-back>Back to Scheduling Setup</a>
            @unless ($firstRun)
                <a href="{{ route('crm.scheduling.configuration.availability.index') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto">Manage availability</a>
            @endunless
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

        @if ($firstRun)
            @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])
        @else
            <x-ui.card class="space-y-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Appointment types</div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">What can someone book?</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Each appointment type keeps its length, appointment format, availability, staff, booking access, and follow-up path together.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:min-w-72">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-slate-500">Appointment types</div><div class="mt-1 text-xl font-semibold text-slate-900">{{ $services->count() }}</div></div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-slate-500">Publicly bookable</div><div class="mt-1 text-xl font-semibold text-slate-900">{{ $shareableServiceCount }}</div></div>
                    </div>
                </div>
            </x-ui.card>

            <section class="space-y-4" data-scheduling-services-list>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div><h2 class="text-xl font-semibold tracking-tight text-slate-900">Your appointment types</h2><p class="mt-1 text-sm text-slate-500">Open one to see its real booking blockers and focused configuration workspaces.</p></div>
                    <div class="text-sm text-slate-500">{{ $services->count() }} total</div>
                </div>

                <div class="space-y-4">
                    @foreach ($services as $service)
                        <x-ui.card class="space-y-5">
                            <div data-bookable-service-id="{{ $service->id }}" data-scheduling-appointment-type-card="{{ $service->id }}" data-crm-editable="{{ $service->getAttribute('crm_editable') ? '1' : '0' }}">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-lg font-semibold text-slate-900">{{ $service->name }}</h3>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ str($service->status)->replace('_', ' ')->title() }}</span>
                                            @if ($service->getAttribute('public_booking_ready'))
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Publicly bookable</span>
                                            @elseif ($service->is_public)
                                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">Public booking needs attention</span>
                                            @else
                                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Internal appointment creation only</span>
                                            @endif
                                            @if (! $service->getAttribute('crm_editable'))
                                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">Managed externally</span>
                                            @endif
                                        </div>
                                        @if ($service->description)<p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ $service->description }}</p>@endif
                                    </div>
                                    <a href="{{ route('crm.scheduling.configuration.services.edit', $service) }}" class="inline-flex w-full shrink-0 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto" data-scheduling-service-edit="{{ $service->id }}">Open setup</a>
                                </div>

                                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><dt class="text-slate-500">Length</dt><dd class="mt-1 font-semibold text-slate-900">@if ($service->usesRangeDuration()){{ $service->minimumDurationMinutes() }}–{{ $service->maximumDurationMinutes() }} min @else {{ $service->duration_minutes }} min @endif</dd></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><dt class="text-slate-500">Appointment format</dt><dd class="mt-1 font-semibold text-slate-900">{{ $service->appointmentMethodLabel() ?? 'Missing' }}</dd></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><dt class="text-slate-500">Staff</dt><dd class="mt-1 font-semibold text-slate-900">{{ $service->getAttribute('active_host_summary') }}</dd></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><dt class="text-slate-500">Next appointment</dt><dd class="mt-1 font-semibold text-slate-900">{{ $service->getAttribute('next_appointment_label') ?? 'None scheduled' }}</dd></div>
                                </dl>

                                @if ($service->getAttribute('public_booking_ready'))
                                    <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4" x-data="{ copied: false }" data-scheduling-booking-link="{{ $service->id }}">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Booking link</div>
                                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                            <input x-ref="bookingLink" class="min-w-0 flex-1 truncate rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm text-slate-700" value="{{ $service->getAttribute('public_booking_url') }}" readonly>
                                            <button type="button" class="rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-900" x-on:click="navigator.clipboard?.writeText($refs.bookingLink.value); copied = true; setTimeout(() => copied = false, 1600)"><span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span></button>
                                        </div>
                                    </div>
                                @elseif ($service->is_public && $service->getAttribute('public_booking_issue'))
                                    <div class="mt-5 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm sm:flex-row sm:items-center sm:justify-between" data-scheduling-public-booking-issue="{{ $service->id }}">
                                        <div><div class="font-semibold text-amber-950">Not shareable yet</div><div class="mt-1 text-amber-900">{{ $service->getAttribute('public_booking_issue') }}</div></div>
                                        <a href="{{ route('crm.scheduling.configuration.services.details.edit', $service) }}#public-booking" class="inline-flex w-full shrink-0 justify-center rounded-lg border border-amber-300 bg-white px-3 py-2 font-semibold text-amber-900 hover:bg-amber-100 sm:w-auto">See how to resolve it</a>
                                    </div>
                                @endif
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        <x-ui.card class="space-y-5" data-configuration-service-create>
            <details @if ($firstRun || $errors->any()) open @endif x-data="{ appointmentMethod: @js(old('appointment_method', '')), publicBooking: @js((bool) old('is_public', false)), publicSurfaceReady: @js($publicSurfaceReady) }">
                <summary class="cursor-pointer list-none">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">{{ $firstRun ? 'Step 1' : 'New appointment type' }}</div>
                            <h2 class="mt-3 text-lg font-semibold text-slate-900">{{ $firstRun ? 'Add the first appointment type' : 'Add another appointment type' }}</h2>
                            <p class="mt-1 max-w-2xl text-sm text-slate-500">Set the name, normal length, and appointment format now. After that, Availability is a real next step instead of a guess.</p>
                        </div>
                        <span class="text-sm font-semibold text-teal-700">{{ $firstRun ? 'Start here' : 'Open form' }}</span>
                    </div>
                </summary>

                <form method="POST" action="{{ route('crm.scheduling.configuration.services.store') }}" class="mt-6 space-y-5">
                    @csrf
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">Name<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="name" value="{{ old('name') }}" required></label>
                        <label class="block text-sm font-medium text-slate-700">Normal length (minutes)<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="number" min="1" max="1440" name="duration_minutes" value="{{ old('duration_minutes', 60) }}" required></label>
                        <label class="block text-sm font-medium text-slate-700 md:col-span-2">Appointment format
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="appointment_method" x-model="appointmentMethod" required>
                                <option value="">Choose how this appointment happens</option>
                                <option value="phone">Phone call</option>
                                <option value="virtual_meeting">Virtual meeting</option>
                                <option value="business_location">At a business location</option>
                                <option value="customer_address">At an address the customer provides</option>
                            </select>
                            <x-ui.form.error name="appointment_method" />
                        </label>
                        <label class="block text-sm font-medium text-slate-700 md:col-span-2">Description <span class="font-normal text-slate-400">(optional)</span><textarea class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="description" rows="3">{{ old('description') }}</textarea></label>

                        <label class="block text-sm font-medium text-slate-700 md:col-span-2" x-show="appointmentMethod === 'virtual_meeting'" x-cloak>Meeting link <span class="font-normal text-slate-400">(optional)</span><input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" type="url" name="location_url" value="{{ old('location_url') }}" placeholder="https://…" x-bind:disabled="appointmentMethod !== 'virtual_meeting'"></label>

                        <div class="grid gap-4 md:col-span-2 md:grid-cols-2" x-show="appointmentMethod === 'business_location'" x-cloak>
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">Location name <span class="font-normal text-slate-400">(optional)</span><input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_label" value="{{ old('location_label') }}" placeholder="Main office" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                            <label class="block text-sm font-medium text-slate-700 md:col-span-2">Street address<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_address_line_1" value="{{ old('location_address_line_1') }}" x-bind:required="appointmentMethod === 'business_location'" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                            <label class="block text-sm font-medium text-slate-700">City<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_city" value="{{ old('location_city') }}" x-bind:required="appointmentMethod === 'business_location'" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                            <label class="block text-sm font-medium text-slate-700">State / region<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_region" value="{{ old('location_region') }}" x-bind:required="appointmentMethod === 'business_location'" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                            <label class="block text-sm font-medium text-slate-700">Postal code<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_postal_code" value="{{ old('location_postal_code') }}" x-bind:required="appointmentMethod === 'business_location'" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                            <label class="block text-sm font-medium text-slate-700">Country code<input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200" name="location_country" value="{{ old('location_country', 'US') }}" maxlength="2" x-bind:required="appointmentMethod === 'business_location'" x-bind:disabled="appointmentMethod !== 'business_location'"></label>
                        </div>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700 md:col-span-2" x-bind:class="appointmentMethod === '' || !publicSurfaceReady ? 'opacity-60' : ''">
                            <input type="hidden" name="is_public" value="0">
                            <input class="mt-0.5" type="checkbox" name="is_public" value="1" x-model="publicBooking" x-bind:disabled="appointmentMethod === '' || !publicSurfaceReady">
                            <span><span class="block font-semibold text-slate-900">Let customers book this themselves</span><span class="mt-0.5 block text-slate-500" x-show="appointmentMethod !== '' && publicSurfaceReady">Turn this on when customers should be able to use a public booking link.</span><span class="mt-0.5 block font-semibold text-amber-700" x-show="appointmentMethod === ''">Choose the appointment format first.</span><span class="mt-0.5 block font-semibold text-amber-700" x-show="appointmentMethod !== '' && !publicSurfaceReady">Public booking is unavailable until the public Scheduling URL is configured for this environment.</span></span>
                        </label>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" x-show="appointmentMethod !== '' && !publicSurfaceReady" x-cloak><span class="font-semibold">Public booking unavailable.</span> Configure the public Scheduling URL for this environment before customer self-booking can be turned on.</div>

                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">Create appointment type</button>
                </form>
            </details>
        </x-ui.card>
    </div>
</x-layouts.crm>