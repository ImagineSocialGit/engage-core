
<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Set up Scheduling, review upcoming appointments, and book a contact into an available time."
>
    <div class="space-y-6">
        <div class="flex sm:justify-end">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-configuration-link
            >
                Manage setup
            </a>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if (session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        @if (! $setupReadiness['internal_ready'])
            <x-ui.card class="space-y-5" data-scheduling-setup-readiness>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Set up Scheduling
                        </div>
                        <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                            Get ready to book the first appointment
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            Start with what people can schedule, then add availability. Staff or providers are optional unless appointments need a specific assignee.
                        </p>
                    </div>

                    <span class="inline-flex self-start rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                        Setup incomplete
                    </span>
                </div>

                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 p-4" data-scheduling-setup-step="service">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $setupReadiness['has_service'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }} text-sm font-semibold">
                                {{ $setupReadiness['has_service'] ? '✓' : '1' }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">What can people schedule?</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Add at least one active service, such as a consultation, lesson, visit, or appointment type.
                                </p>
                                <a href="{{ route('crm.scheduling.configuration.index') }}#services" class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:underline">
                                    {{ $setupReadiness['has_service'] ? 'Review services' : 'Add the first service' }}
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4" data-scheduling-setup-step="people">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $setupReadiness['has_active_host'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }} text-sm font-semibold">
                                {{ $setupReadiness['has_active_host'] ? '✓' : '2' }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">Who handles appointments?</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Optional. Add staff or providers only when a booking needs to be assigned to a specific person.
                                </p>
                                <a href="{{ route('crm.scheduling.configuration.index') }}#people" class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:underline">
                                    {{ $setupReadiness['has_active_host'] ? 'Review staff and providers' : 'Add staff or a provider' }}
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4" data-scheduling-setup-step="availability">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $setupReadiness['has_availability'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }} text-sm font-semibold">
                                {{ $setupReadiness['has_availability'] ? '✓' : '3' }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">When can appointments happen?</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Add at least one available time so Scheduling can offer or create appointments.
                                </p>
                                <a href="{{ route('crm.scheduling.configuration.availability.index') }}" class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:underline">
                                    {{ $setupReadiness['has_availability'] ? 'Review availability' : 'Set availability' }}
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4" data-scheduling-setup-step="test">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">
                                4
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">Book a test appointment</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Once a service and availability are ready, come back here and schedule a contact to make sure the setup behaves the way you expect.
                                </p>
                                @if ($setupReadiness['has_service'] && $setupReadiness['has_availability'])
                                    <span class="mt-3 inline-flex text-sm font-semibold text-emerald-700">Ready to test</span>
                                @else
                                    <span class="mt-3 inline-flex text-sm font-medium text-slate-400">Finish the required steps first</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        @endif

        @if (! $setupReadiness['empty'])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-scheduling-routine-workspace>
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Upcoming
                </p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $upcomingAppointments->count() }}
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    Active appointments starting from now forward.
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Awaiting confirmation
                </p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $pendingCount }}
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    Appointments that still need confirmation.
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Active services
                </p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $services->count() }}
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    Services currently eligible for direct appointment creation.
                </p>
            </x-ui.card>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(360px,0.85fr)]">
            <x-ui.card class="space-y-5">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Upcoming appointments
                    </div>

                    <h2 class="mt-3 text-lg font-semibold tracking-tight text-slate-900">
                        What is scheduled next
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Pending, scheduled, and confirmed appointments appear here in chronological order.
                    </p>
                </div>

                @if($upcomingAppointments->isEmpty())
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center sm:p-6">
                        <p class="font-semibold text-slate-900">
                            No upcoming appointments
                        </p>
                        <p class="mt-1 text-sm text-slate-500">
                            Use the scheduling form to create the first appointment.
                        </p>
                    </div>
                @else
                    <div class="divide-y divide-slate-200 rounded-xl border border-slate-200">
                        @foreach($upcomingAppointments as $appointment)
                            @php
                                $displayTimezone = in_array($appointment->timezone, timezone_identifiers_list(), true)
                                    ? $appointment->timezone
                                    : config('client.timezone', 'UTC');
                                $primarySnapshot = $appointment->attendees->first();
                                $contactLabel = $appointment->contact?->name
                                    ?: $primarySnapshot?->name
                                    ?: $appointment->contact?->email
                                    ?: $primarySnapshot?->email
                                    ?: 'Unidentified attendee';
                                $statusClasses = match($appointment->status) {
                                    \App\Modules\Scheduling\Models\Appointment::STATUS_PENDING => 'bg-amber-100 text-amber-800',
                                    \App\Modules\Scheduling\Models\Appointment::STATUS_CONFIRMED => 'bg-emerald-100 text-emerald-800',
                                    default => 'bg-sky-100 text-sky-800',
                                };
                            @endphp

                            <article class="p-4 sm:p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <a
                                            href="{{ route('crm.scheduling.appointments.show', $appointment) }}"
                                            class="font-semibold text-slate-900 hover:text-teal-700 hover:underline"
                                        >
                                            {{ $appointment->title ?: $appointment->bookableService?->name ?: 'Appointment' }}
                                        </a>

                                        <p class="mt-1 break-words text-sm text-slate-600">
                                            {{ $contactLabel }}
                                        </p>

                                        <p class="mt-2 break-words text-sm font-medium text-slate-900">
                                            {{ $appointment->starts_at->setTimezone($displayTimezone)->format('D, M j, Y \a\t g:i A') }}
                                            –
                                            {{ $appointment->ends_at->setTimezone($displayTimezone)->format($appointment->bookableService?->usesRangeDuration() ? 'D, M j, Y \a\t g:i A' : 'g:i A') }}
                                        </p>

                                        <p class="mt-1 break-words text-xs text-slate-500">
                                            {{ $displayTimezone }}
                                            @if($appointment->schedulingHost)
                                                · {{ $appointment->schedulingHost->name }}
                                            @endif
                                        </p>
                                    </div>

                                    <span class="inline-flex self-start rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                        {{ str($appointment->status)->replace('_', ' ')->title() }}
                                    </span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <div class="space-y-6">
                <x-ui.card class="space-y-5">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Quick schedule
                        </div>

                        <h2 class="mt-3 text-lg font-semibold tracking-tight text-slate-900">
                            Choose a service and time
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Times shown reflect the current hours, assigned staff, existing appointments, and other booking limits.
                        </p>
                    </div>

                    <form
                        method="GET"
                        action="{{ route('crm.scheduling.index') }}"
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
                                        <span class="mt-1 block text-xs text-slate-500">Find someone already in Engage Core.</span>
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
                                    If this email already belongs to a Contact, Engage Core will use that Contact instead of creating a duplicate. Booking someone does not grant marketing consent.
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
                                                @php
                                                    $rangeStart = $range['starts_at']->setTimezone($range['display_timezone']);
                                                    $rangeEnd = $range['last_start_at']->setTimezone($range['display_timezone']);
                                                @endphp
                                                <div class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between" data-start-range-first="{{ $range['starts_at']->toISOString() }}" data-start-range-last="{{ $range['last_start_at']->toISOString() }}">
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-900">
                                                            {{ $rangeStart->format('g:i A') }}{{ $range['slot_count'] > 1 ? '–'.$rangeEnd->format('g:i A') : '' }}
                                                        </p>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            {{ $range['slot_count'] > 1 ? 'Start every '.$range['interval_minutes'].' minutes' : 'One available start' }} · {{ $range['display_timezone'] }}
                                                        </p>
                                                    </div>
                                                    <span class="text-xs font-semibold text-slate-600">{{ $range['remaining_capacity'] }} open {{ $range['remaining_capacity'] === 1 ? 'spot' : 'spots' }} per start</span>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="mt-4">
                                            <x-ui.form.label for="starts_at">Choose exact start time</x-ui.form.label>
                                            <x-ui.form.select id="starts_at" name="starts_at" x-model="selectedStart">
                                                <option value="">Choose a start time</option>
                                                @foreach($availableStartRanges as $range)
                                                    @php
                                                        $groupStart = $range['starts_at']->setTimezone($range['display_timezone']);
                                                        $groupEnd = $range['last_start_at']->setTimezone($range['display_timezone']);
                                                        $groupLabel = $groupStart->format('g:i A').($range['slot_count'] > 1 ? '–'.$groupEnd->format('g:i A') : '');
                                                    @endphp
                                                    <optgroup label="{{ $groupLabel }}">
                                                        @foreach($range['slots'] as $slot)
                                                            @php $slotValue = $slot->startsAt->toIso8601String(); @endphp
                                                            <option value="{{ $slotValue }}" @selected(old('starts_at') === $slotValue)>
                                                                {{ $slot->localStartsAt()->format('g:i A') }}
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
        </div>
        @endif
    </div>
</x-layouts.crm>