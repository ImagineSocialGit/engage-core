<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review upcoming appointments and schedule a contact into a currently available time."
>
    <div class="space-y-6">
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

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
                    Pending appointments that still need lifecycle follow-up.
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
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
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
                                        <p class="font-semibold text-slate-900">
                                            {{ $appointment->title ?: $appointment->bookableService?->name ?: 'Appointment' }}
                                        </p>

                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ $contactLabel }}
                                        </p>

                                        <p class="mt-2 text-sm font-medium text-slate-900">
                                            {{ $appointment->starts_at->setTimezone($displayTimezone)->format('D, M j, Y \a\t g:i A') }}
                                            –
                                            {{ $appointment->ends_at->setTimezone($displayTimezone)->format('g:i A') }}
                                        </p>

                                        <p class="mt-1 text-xs text-slate-500">
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
                            Availability is recalculated from current service, host, window, hold, and appointment state.
                        </p>
                    </div>

                    <form
                        method="GET"
                        action="{{ route('crm.scheduling.index') }}"
                        class="space-y-4"
                    >
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
                                        Host
                                    </x-ui.form.label>

                                    <x-ui.form.select
                                        id="scheduling_host_id"
                                        name="scheduling_host_id"
                                        onchange="this.form.submit()"
                                    >
                                        <option value="">Choose a host</option>

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
                                            This service has no active assigned host.
                                        </p>
                                    @endif
                                </div>
                            @endif


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
                        @endif
                    </form>
                </x-ui.card>

                @if($selectedService)
                    <x-ui.card class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-slate-900">
                                Schedule the appointment
                            </h2>

                            <p class="mt-1 text-sm text-slate-500">
                                Select an existing contact and one currently available time.
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.appointments.store') }}"
                            class="space-y-5"
                            x-data="{
                                query: @js($selectedContact?->name ?: $selectedContact?->email ?: ''),
                                selectedId: @js((string) old('contact_id', $selectedContact?->id ?? '')),
                                results: [],
                                loading: false,
                                open: false,
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
                            }"
                            x-on:click.outside="open = false"
                        >
                            @csrf

                            <input type="hidden" name="bookable_service_id" value="{{ $selectedService->id }}">
                            <input type="hidden" name="scheduling_host_id" value="{{ $selectedHost?->id }}">
                            <input type="hidden" name="date" value="{{ $selectedDate->toDateString() }}">
                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                            <input type="hidden" name="contact_id" x-model="selectedId">

                            <div class="relative">
                                <x-ui.form.label for="contact_search">
                                    Contact
                                </x-ui.form.label>

                                <x-ui.form.input
                                    id="contact_search"
                                    name="contact_search"
                                    value=""
                                    autocomplete="off"
                                    placeholder="Search by name, email, or phone"
                                    x-model="query"
                                    x-on:input.debounce.250ms="search()"
                                    x-on:focus="query.trim().length >= 2 && (open = true)"
                                />

                                <p x-show="loading" class="mt-2 text-xs text-slate-500">
                                    Searching contacts…
                                </p>

                                <div
                                    x-show="open"
                                    x-cloak
                                    class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg"
                                >
                                    <template x-for="contact in results" :key="contact.id">
                                        <button
                                            type="button"
                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100"
                                            x-on:click="choose(contact)"
                                        >
                                            <span class="font-medium text-slate-900" x-text="contact.label"></span>
                                        </button>
                                    </template>

                                    <p
                                        x-show="!loading && results.length === 0"
                                        class="px-3 py-2 text-sm text-slate-500"
                                    >
                                        No matching contacts found.
                                    </p>
                                </div>

                                <x-ui.form.error name="contact_id" />
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-slate-700">
                                    Available time
                                </span>

                                @if($requiresHost && ! $selectedHost)
                                    <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                        Choose an active assigned host before selecting a time.
                                    </p>
                                @elseif($slots === [])
                                    <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                                        No appointment times are currently available for this date.
                                    </p>
                                @else
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach($slots as $slot)
                                            @php
                                                $slotValue = $slot->startsAt->toIso8601String();
                                                $slotLabel = $slot->startsAt
                                                    ->setTimezone($selectedService->timezone)
                                                    ->format('g:i A')
                                                    .'–'
                                                    .$slot->endsAt
                                                        ->setTimezone($selectedService->timezone)
                                                        ->format('g:i A');
                                            @endphp

                                            <label class="cursor-pointer rounded-xl border border-slate-200 p-3 hover:border-slate-400 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                                                <input
                                                    type="radio"
                                                    name="starts_at"
                                                    value="{{ $slotValue }}"
                                                    class="sr-only"
                                                    @checked(old('starts_at') === $slotValue)
                                                >
                                                <span class="block text-sm font-semibold text-slate-900">
                                                    {{ $slotLabel }}
                                                </span>
                                                <span class="mt-1 block text-xs text-slate-500">
                                                    {{ $selectedService->timezone }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif

                                <x-ui.form.error name="starts_at" />
                                <x-ui.form.error name="bookable_service_id" />
                                <x-ui.form.error name="scheduling_host_id" />
                                <x-ui.form.error name="idempotency_key" />
                            </div>

                            <x-ui.button
                                type="submit"
                                class="w-full justify-center"
                                :disabled="($requiresHost && ! $selectedHost) || $slots === []"
                            >
                                Schedule Appointment
                            </x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            </div>
        </div>
    </div>
</x-layouts.crm>