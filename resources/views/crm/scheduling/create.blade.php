<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Create one appointment in a focused workspace. Choose what is being booked, who it is for, and an available time."
>
    <div class="space-y-6" data-scheduling-appointment-create>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Back to Scheduling
            </a>
            <a
                href="{{ route('crm.scheduling.configuration.services.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Manage appointment types
            </a>
        </div>

        @if(session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
        @endif

        <x-ui.card class="space-y-4" data-scheduling-create-progress>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">Schedule appointment</div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">One focused booking flow</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">Select the appointment type and time, identify the attendee, then review the details before creating the appointment.</p>
            </div>
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="font-semibold text-slate-900">1. Appointment type</span><span class="mt-1 block text-slate-500">Choose what is being booked.</span></div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="font-semibold text-slate-900">2. Person</span><span class="mt-1 block text-slate-500">Find or add the attendee.</span></div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="font-semibold text-slate-900">3. Time</span><span class="mt-1 block text-slate-500">Use authoritative availability.</span></div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="font-semibold text-slate-900">4. Schedule</span><span class="mt-1 block text-slate-500">Create the appointment.</span></div>
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-5" data-scheduling-create-selection>
            <div>
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Appointment type and date</h2>
                <p class="mt-1 text-sm text-slate-500">Changing these choices refreshes the available staff and times before you enter the appointment details.</p>
            </div>
<form
    method="GET"
    action="{{ route('crm.scheduling.appointments.create') }}"
    class="space-y-4"
>
    @if($selectedContact)
        <input type="hidden" name="contact_id" value="{{ $selectedContact->id }}">

        <div
            class="break-words rounded-xl border border-teal-200 bg-teal-50 p-3 text-sm text-teal-950"
            data-scheduling-preselected-contact="{{ $selectedContact->id }}"
        >
            <span class="font-semibold">Scheduling for:</span>
            {{ $selectedContactLabel }}
        </div>
    @endif

    <div>
        <x-ui.form.label for="bookable_service_id">
            Service
        </x-ui.form.label>

        <x-ui.form.select
            id="bookable_service_id"
            name="bookable_service_id"
            onchange="this.form.submit()"
        >
            <option value="">Choose a service</option>

            @foreach($services as $service)
                <option
                    value="{{ $service->id }}"
                    @selected($selectedService?->is($service))
                >
                    {{ $service->name }}
                </option>
            @endforeach
        </x-ui.form.select>
    </div>

    @if($selectedService)
        @if($requiresHost)
            <div>
                <x-ui.form.label for="scheduling_host_id">
                    Staff / provider
                </x-ui.form.label>

                <x-ui.form.select
                    id="scheduling_host_id"
                    name="scheduling_host_id"
                    onchange="this.form.submit()"
                >
                    <option value="">Choose staff / provider</option>

                    @foreach($hosts as $host)
                        <option
                            value="{{ $host->id }}"
                            @selected($selectedHost?->is($host))
                        >
                            {{ $host->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                @if($hosts->isEmpty())
                    <p class="mt-2 text-xs font-semibold text-amber-700">
                        No active staff or provider is assigned to this service.
                    </p>
                @endif
            </div>
        @endif


        @if($selectedService->usesFixedDuration())
            <div>
                <x-ui.form.label for="date">
                    Date
                </x-ui.form.label>

                <x-ui.form.input
                    id="date"
                    name="date"
                    type="date"
                    value="{{ $selectedDate->toDateString() }}"
                    min="{{ $dateMinimum->toDateString() }}"
                    max="{{ $dateMaximum->toDateString() }}"
                    onchange="this.form.submit()"
                />

                @unless($dateInRange)
                    <p class="mt-2 text-xs font-semibold text-amber-700">
                        Choose a date between {{ $dateMinimum->format('M j, Y') }} and {{ $dateMaximum->format('M j, Y') }}.
                    </p>
                @endunless
            </div>
        @else
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 md:col-span-2">
                Enter the exact check-in and check-out below. Scheduling validates the complete stay interval in {{ $selectedService->timezone }}.
            </div>
        @endif
    @endif
</form>
        </x-ui.card>

@if($selectedService)
    <x-ui.card class="space-y-5">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-slate-900">
                {{ $selectedService->usesRangeDuration() ? 'Schedule the stay' : 'Schedule the appointment' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Tell us who this is for, then choose an available time.
            </p>
        </div>

        @if($errors->any())
            <div data-scheduling-validation-summary>
                <x-ui.feedback.alert type="error">
                    We couldn't schedule this yet. Check the highlighted information below and try again.
                </x-ui.feedback.alert>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('crm.scheduling.appointments.store') }}"
            class="space-y-5"
            x-data="{
                attendeeMode: @js(old('attendee_mode', $selectedContact ? 'contact' : 'contact')),
                query: @js(old('contact_search', $selectedContactLabel)),
                selectedId: @js((string) old('contact_id', $selectedContact?->id ?? '')),
                attendeeName: @js(old('attendee_name', '')),
                attendeeEmail: @js(old('attendee_email', '')),
                attendeePhone: @js(old('attendee_phone', '')),
                selectedStart: @js(old('starts_at', '')),
                rangeStartsAt: @js(old('range_starts_at', '')),
                rangeEndsAt: @js(old('range_ends_at', '')),
                results: [],
                loading: false,
                open: false,
                fixedDuration: @js($selectedService->usesFixedDuration()),
                hostReady: @js(! $requiresHost || $selectedHost !== null),
                fixedTimesAvailable: @js($slots !== []),
                async search() {
                    this.selectedId = '';
                    const value = this.query.trim();
                    if (value.length < 2) {
                        this.results = [];
                        this.open = false;
                        return;
                    }
                    this.loading = true;
                    try {
                        const response = await fetch(
                            @js(route('crm.contacts.lookup')) + '?q=' + encodeURIComponent(value),
                            { headers: { Accept: 'application/json' } },
                        );
                        const payload = response.ok ? await response.json() : { contacts: [] };
                        this.results = Array.isArray(payload.contacts) ? payload.contacts : [];
                        this.open = true;
                    } finally {
                        this.loading = false;
                    }
                },
                choose(contact) {
                    this.selectedId = String(contact.id);
                    this.query = contact.label;
                    this.results = [];
                    this.open = false;
                },
                personReady() {
                    if (this.attendeeMode === 'contact') return this.selectedId !== '';
                    if (this.attendeeMode === 'new_contact') return this.attendeeName.trim() !== '' && this.attendeeEmail.trim() !== '';
                    if (this.attendeeMode === 'guest') return this.attendeeName.trim() !== '';
                    return false;
                },
                timeReady() {
                    if (this.fixedDuration) return this.fixedTimesAvailable && this.selectedStart !== '';
                    return this.rangeStartsAt !== '' && this.rangeEndsAt !== '';
                },
                canSubmit() {
                    return this.hostReady && this.personReady() && this.timeReady();
                },
            }"
            x-on:click.outside="open = false"
        >
            @csrf

            <input type="hidden" name="bookable_service_id" value="{{ $selectedService->id }}">
            <input type="hidden" name="scheduling_host_id" value="{{ $selectedHost?->id }}">
            <input type="hidden" name="date" value="{{ $selectedDate->toDateString() }}">
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <input type="hidden" name="contact_id" x-model="selectedId" x-bind:disabled="attendeeMode !== 'contact'">

            <fieldset class="space-y-3" data-scheduling-attendee-mode>
                <legend class="text-sm font-semibold text-slate-900">Who is this appointment for?</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                        <input type="radio" name="attendee_mode" value="contact" class="sr-only" x-model="attendeeMode">
                        <span class="block text-sm font-semibold text-slate-900">Existing Contact</span>
                        <span class="mt-1 block text-xs text-slate-500">Find someone already in Contacts.</span>
                    </label>
                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                        <input type="radio" name="attendee_mode" value="new_contact" class="sr-only" x-model="attendeeMode">
                        <span class="block text-sm font-semibold text-slate-900">New person</span>
                        <span class="mt-1 block text-xs text-slate-500">Add or match them in Contacts while booking.</span>
                    </label>
                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                        <input type="radio" name="attendee_mode" value="guest" class="sr-only" x-model="attendeeMode">
                        <span class="block text-sm font-semibold text-slate-900">Don't add to Contacts</span>
                        <span class="mt-1 block text-xs text-slate-500">Keep attendee details only on this appointment.</span>
                    </label>
                </div>
                <x-ui.form.error name="attendee_mode" />
            </fieldset>

            <div x-show="attendeeMode === 'contact'" x-cloak class="relative">
                <x-ui.form.label for="contact_search">Existing Contact</x-ui.form.label>
                <x-ui.form.input
                    id="contact_search"
                    name="contact_search"
                    value=""
                    autocomplete="off"
                    placeholder="Search by name, email, or phone"
                    x-model="query"
                    x-bind:disabled="attendeeMode !== 'contact'"
                    x-on:input.debounce.250ms="search()"
                    x-on:focus="query.trim().length >= 2 && (open = true)"
                />

                <p x-show="selectedId" class="mt-2 text-xs font-semibold text-teal-700" data-scheduling-contact-selected>
                    Contact selected.
                </p>
                <p x-show="query.trim().length > 0 && !selectedId && !loading" class="mt-2 text-xs font-semibold text-amber-700" data-scheduling-contact-unselected>
                    Choose a Contact from the search results before scheduling.
                </p>
                <p x-show="loading" class="mt-2 text-xs text-slate-500">Searching contacts…</p>

                <div
                    x-show="open"
                    x-cloak
                    class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg"
                >
                    <template x-for="contact in results" :key="contact.id">
                        <button type="button" class="block w-full break-words rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100" x-on:click="choose(contact)">
                            <span class="font-medium text-slate-900" x-text="contact.label"></span>
                        </button>
                    </template>
                    <p x-show="!loading && results.length === 0" class="px-3 py-2 text-sm text-slate-500">No matching Contacts found.</p>
                </div>
                <x-ui.form.error name="contact_id" />
            </div>

            <div x-show="attendeeMode !== 'contact'" x-cloak class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div x-show="attendeeMode === 'new_contact'" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
                    If this email already belongs to a Contact, that Contact will be used instead of creating a duplicate. Booking someone does not grant marketing consent.
                </div>
                <div x-show="attendeeMode === 'guest'" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    This person will not be added to Contacts. Contact-linked history, communication, and CRM follow-up will not be available unless the appointment is connected to a Contact later.
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-ui.form.label for="attendee_name">Name</x-ui.form.label>
                        <x-ui.form.input id="attendee_name" name="attendee_name" value="" x-model="attendeeName" x-bind:disabled="attendeeMode === 'contact'" />
                        <x-ui.form.error name="attendee_name" />
                    </div>
                    <div>
                        <x-ui.form.label for="attendee_email">
                            Email <span x-show="attendeeMode === 'new_contact'" class="text-slate-500">(required)</span>
                        </x-ui.form.label>
                        <x-ui.form.input id="attendee_email" name="attendee_email" type="email" value="" x-model="attendeeEmail" x-bind:required="attendeeMode === 'new_contact'" x-bind:disabled="attendeeMode === 'contact'" autocomplete="email" />
                        <x-ui.form.error name="attendee_email" />
                    </div>
                    <div>
                        <x-ui.form.label for="attendee_phone">Phone</x-ui.form.label>
                        <x-ui.form.input id="attendee_phone" name="attendee_phone" value="" x-model="attendeePhone" x-bind:disabled="attendeeMode === 'contact'" autocomplete="tel" />
                        <x-ui.form.error name="attendee_phone" />
                    </div>
                </div>
            </div>

            <div>
                <x-ui.form.label for="attendee_context">Appointment context <span class="text-slate-500">(optional)</span></x-ui.form.label>
                <textarea id="attendee_context" name="attendee_context" rows="3" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">{{ old('attendee_context') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Add anything the person handling the appointment should know.</p>
                <x-ui.form.error name="attendee_context" />
            </div>

            @if($selectedService->location_type === \App\Modules\Scheduling\Models\BookableService::LOCATION_TYPE_CUSTOMER_SITE)
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-900">Customer service address</h3>
                    <p class="mt-1 break-words text-xs text-slate-500">Enter the address where this appointment will take place.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2"><x-ui.form.label for="address_line_1">Address line 1</x-ui.form.label><x-ui.form.input id="address_line_1" name="address_line_1" value="{{ old('address_line_1') }}" autocomplete="address-line1" /><x-ui.form.error name="address_line_1" /></div>
                        <div class="sm:col-span-2"><x-ui.form.label for="address_line_2">Address line 2</x-ui.form.label><x-ui.form.input id="address_line_2" name="address_line_2" value="{{ old('address_line_2') }}" autocomplete="address-line2" /><x-ui.form.error name="address_line_2" /></div>
                        <div><x-ui.form.label for="city">City</x-ui.form.label><x-ui.form.input id="city" name="city" value="{{ old('city') }}" autocomplete="address-level2" /><x-ui.form.error name="city" /></div>
                        <div><x-ui.form.label for="region">State / region</x-ui.form.label><x-ui.form.input id="region" name="region" value="{{ old('region') }}" autocomplete="address-level1" /><x-ui.form.error name="region" /></div>
                        <div><x-ui.form.label for="postal_code">Postal code</x-ui.form.label><x-ui.form.input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" autocomplete="postal-code" /><x-ui.form.error name="postal_code" /></div>
                        <div><x-ui.form.label for="country">Country code</x-ui.form.label><x-ui.form.input id="country" name="country" value="{{ old('country', 'US') }}" maxlength="2" autocomplete="country" /><x-ui.form.error name="country" /></div>
                    </div>
                </div>
            @endif

            <div>
                @if($selectedService->usesRangeDuration())
                    <span class="block text-sm font-medium text-slate-700">Stay interval</span>
                    @if($requiresHost && ! $selectedHost)
                        <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Choose assigned staff or a provider before entering the stay interval.</p>
                    @else
                        <div class="mt-2 grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-ui.form.label for="range_starts_at">Check-in</x-ui.form.label>
                                <x-ui.form.input id="range_starts_at" name="range_starts_at" type="datetime-local" value="{{ old('range_starts_at') }}" x-model="rangeStartsAt" />
                                <x-ui.form.error name="range_starts_at" />
                            </div>
                            <div>
                                <x-ui.form.label for="range_ends_at">Check-out</x-ui.form.label>
                                <x-ui.form.input id="range_ends_at" name="range_ends_at" type="datetime-local" value="{{ old('range_ends_at') }}" x-model="rangeEndsAt" />
                                <x-ui.form.error name="range_ends_at" />
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Times are interpreted in {{ $selectedService->timezone }}. Allowed duration: {{ $selectedService->minimumDurationMinutes() }}–{{ $selectedService->maximumDurationMinutes() }} minutes.</p>
                    @endif
                @else
                    <span class="block text-sm font-medium text-slate-700">Available appointment start times</span>
                    @if($requiresHost && ! $selectedHost)
                        <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Choose assigned staff or a provider before selecting a time.</p>
                    @elseif($availableStartRanges === [])
                        <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">No appointment start times are currently available for this date.</p>
                    @else
                        <div class="mt-2 space-y-2" data-scheduling-start-ranges>
                            @foreach($availableStartRanges as $range)
                                <div class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between" data-start-range-first="{{ $range['first_iso'] }}" data-start-range-last="{{ $range['last_iso'] }}">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ $range['display_label'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $range['cadence_label'] }} · {{ $range['display_timezone'] }}</p>
                                    </div>
                                    <span class="text-xs font-semibold text-slate-600">{{ $range['capacity_label'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4">
                            <x-ui.form.label for="starts_at">Choose exact start time</x-ui.form.label>
                            <x-ui.form.select id="starts_at" name="starts_at" x-model="selectedStart">
                                <option value="">Choose a start time</option>
                                @foreach($availableStartRanges as $range)
                                    <optgroup label="{{ $range['display_label'] }}">
                                        @foreach($range['slot_options'] as $slotOption)
                                            <option value="{{ $slotOption['value'] }}" @selected(old('starts_at') === $slotOption['value'])>
                                                {{ $slotOption['label'] }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-ui.form.select>
                        </div>
                    @endif
                    <x-ui.form.error name="starts_at" />
                @endif

                <x-ui.form.error name="bookable_service_id" />
                <x-ui.form.error name="scheduling_host_id" />
                <x-ui.form.error name="idempotency_key" />
            </div>

            <x-ui.button type="submit" class="w-full justify-center" x-bind:disabled="!canSubmit()">
                {{ $selectedService->usesRangeDuration() ? 'Schedule Stay' : 'Schedule Appointment' }}
            </x-ui.button>
            <p x-show="!canSubmit()" class="text-center text-xs text-slate-500" data-scheduling-submit-help>
                Choose who this is for and a valid time before scheduling.
            </p>
        </form>
    </x-ui.card>
@endif
    </div>
</x-layouts.crm>