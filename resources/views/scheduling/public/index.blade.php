<x-layouts.public-surface
    :title="$publicPresentation['heading']"
    :robots="$publicRobots"
    :primary-color="$publicPresentation['primary_color']"
    :accent-color="$publicPresentation['accent_color']"
    :surface-color="$publicPresentation['surface_color']"
    :background-color="$publicPresentation['background_color']"
    body-class="bg-[var(--public-background)] text-slate-950"
    header-class="border-b border-slate-200/80 bg-white/95 backdrop-blur"
    footer-class="border-t border-slate-200 bg-white"
>
    <x-slot:header>
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
            <a
                href="{{ route('scheduling.public.index', [], false) }}"
                class="inline-flex min-h-12 items-center text-slate-950 no-underline"
                aria-label="{{ $publicPresentation['brand_name'] }} appointments"
            >
                @if($publicPresentation['logo_url'])
                    <img
                        src="{{ $publicPresentation['logo_url'] }}"
                        alt="{{ $publicPresentation['brand_name'] }}"
                        class="max-h-12 max-w-52 object-contain"
                    >
                @else
                    <span class="text-base font-extrabold tracking-tight sm:text-lg">
                        {{ $publicPresentation['brand_name'] }}
                    </span>
                @endif
            </a>
        </div>
    </x-slot:header>

    <x-slot:footer>
        <div class="mx-auto w-full max-w-6xl px-4 py-6 text-center text-xs leading-5 text-slate-500 sm:px-6">
            {{ $publicPresentation['brand_name'] }}
        </div>
    </x-slot:footer>

    <div
        class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:py-16"
        data-scheduling-public-booking
        data-booking-state="{{ $pageState }}"
    >
        @if($errors->any())
            <div
                class="mx-auto mb-6 max-w-3xl rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900 shadow-sm"
                role="alert"
                aria-labelledby="booking-errors-title"
            >
                <p id="booking-errors-title" class="font-extrabold">Please check the highlighted information and try again.</p>
            </div>
        @endif

        @if($holdSummary)
            <div class="mx-auto max-w-3xl">
                <x-public-surface.card aria-live="polite">
                    @if($holdSummary['status'] === 'active')
                        <span class="inline-flex rounded-full bg-[var(--public-primary)]/10 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-[var(--public-primary)]">Time reserved</span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Complete your booking</h1>
                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">This time is held while you add your contact details.</p>
                    @elseif($holdSummary['status'] === 'converted' && $holdSummary['confirmation_pending'])
                        <span class="inline-flex rounded-full bg-[var(--public-primary)]/10 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-[var(--public-primary)]">Request received</span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">We received your request</h1>
                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">The team will review it and contact you using the information you provided.</p>
                    @elseif($holdSummary['status'] === 'converted')
                        <span class="inline-flex rounded-full bg-[var(--public-primary)]/10 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-[var(--public-primary)]">Appointment booked</span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">You’re all set</h1>
                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">Your appointment has been booked. Save the details below.</p>
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-slate-600">Time no longer available</span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Choose another time</h1>
                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">This reservation expired before the booking was completed.</p>
                    @endif

                    @include('scheduling.public.partials.appointment-summary', ['summary' => $holdSummary])

                    @if($holdSummary['status'] === 'active')
                        <p
                            class="mt-5 text-sm font-bold text-slate-600"
                            data-countdown
                            data-expires-at="{{ $holdSummary['expires_at'] }}"
                            data-expired-message="This reservation has expired. Refresh to choose another time."
                        >
                            Reserved for {{ $holdSummary['remaining_minutes'] }} more minute(s).
                        </p>

                        <form
                            class="mt-7 grid gap-5"
                            method="POST"
                            action="{{ route('scheduling.public.holds.complete', ['holdId' => $holdSummary['hold_id']], false) }}"
                            data-booking-details-form
                        >
                            @csrf
                            <input type="hidden" name="public_submission_attempt_id" value="">

                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="grid gap-2 text-sm font-bold text-slate-800" for="first_name">
                                    First name
                                    <input
                                        class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                        id="first_name"
                                        name="first_name"
                                        type="text"
                                        value="{{ old('first_name') }}"
                                        autocomplete="given-name"
                                        maxlength="120"
                                        required
                                    >
                                    @error('first_name')
                                        <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="grid gap-2 text-sm font-bold text-slate-800" for="last_name">
                                    Last name
                                    <input
                                        class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                        id="last_name"
                                        name="last_name"
                                        type="text"
                                        value="{{ old('last_name') }}"
                                        autocomplete="family-name"
                                        maxlength="120"
                                        required
                                    >
                                    @error('last_name')
                                        <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="grid gap-2 text-sm font-bold text-slate-800" for="email">
                                    Email address
                                    <input
                                        class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition read-only:bg-slate-100 focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                        id="email"
                                        name="email"
                                        type="email"
                                        value="{{ old('email', $contactPrefill['email']) }}"
                                        autocomplete="email"
                                        maxlength="255"
                                        @readonly($contactPrefill['verified_channel'] === 'email')
                                        required
                                    >
                                    @if($contactPrefill['verified_channel'] === 'email')
                                        <span class="text-xs font-bold text-[var(--public-primary)]">Verified email</span>
                                    @endif
                                    @error('email')
                                        <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="grid gap-2 text-sm font-bold text-slate-800" for="phone">
                                    <span>
                                        Phone number
                                        @if(($holdSummary['location_type'] ?? null) !== 'phone')
                                            <span class="font-medium text-slate-500">(optional)</span>
                                        @endif
                                    </span>
                                    <input
                                        class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition read-only:bg-slate-100 focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        value="{{ old('phone', $contactPrefill['phone']) }}"
                                        autocomplete="tel"
                                        inputmode="tel"
                                        maxlength="24"
                                        placeholder="(555) 555-0123"
                                        data-phone-mask
                                        data-phone-required="{{ ($holdSummary['location_type'] ?? null) === 'phone' ? 'true' : 'false' }}"
                                        @required(($holdSummary['location_type'] ?? null) === 'phone')
                                        @readonly($contactPrefill['verified_channel'] === 'sms')
                                    >
                                    @if($contactPrefill['verified_channel'] === 'sms')
                                        <span class="text-xs font-bold text-[var(--public-primary)]">Verified phone number</span>
                                    @endif
                                    @error('phone')
                                        <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
                                    @enderror
                                </label>
                            </div>

                            <div class="flex justify-end">
                                <x-public-surface.button type="submit">Complete booking</x-public-surface.button>
                            </div>
                            <p class="max-w-2xl text-xs leading-5 text-slate-500">{{ $publicPresentation['consent_text'] }}</p>
                        </form>
                    @elseif(!in_array($holdSummary['status'], ['converted'], true))
                        <div class="mt-6">
                            <x-public-surface.button
                                :href="route('scheduling.public.services.show', array_filter(['serviceKey' => $holdSummary['service_key'], 'date' => $holdSummary['is_range'] ? null : $holdSummary['date']]), false)"
                            >
                                View available times
                            </x-public-surface.button>
                        </div>
                    @endif
                </x-public-surface.card>
            </div>
        @elseif($offerSummary)
            <div class="mx-auto max-w-3xl">
                <a
                    class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-[var(--public-primary)]"
                    href="{{ route('scheduling.public.services.show', array_filter(['serviceKey' => $offerSummary['service_key'], 'date' => $offerSummary['is_range'] ? null : $offerSummary['date']]), false) }}"
                >
                    <span aria-hidden="true">←</span> Change time
                </a>

                <x-public-surface.card aria-live="polite">
                    @if($offerSummary['status'] === 'active')
                        <span class="inline-flex rounded-full bg-[var(--public-primary)]/10 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-[var(--public-primary)]">Selected time</span>
                        @if($destinationVerification['required'])
                            <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Confirm it’s you</h1>
                            <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">We’ll send a short code before reserving this appointment.</p>
                        @else
                            <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Review your appointment</h1>
                            <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">Continue to reserve this time while you add your contact details.</p>
                        @endif
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-slate-600">Selection expired</span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">This time needs to be selected again</h1>
                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">No appointment was reserved.</p>
                    @endif

                    @include('scheduling.public.partials.appointment-summary', ['summary' => $offerSummary])

                    @if($offerSummary['status'] === 'active')
                        <p
                            class="mt-5 text-sm font-bold text-slate-600"
                            data-countdown
                            data-expires-at="{{ $offerSummary['expires_at'] }}"
                            data-expired-message="This selection has expired. Refresh to choose another time."
                        >
                            {{ $offerSummary['remaining_minutes'] }} minute(s) remaining.
                        </p>

                        @if($destinationVerification['required'])
                            @if($destinationVerification['challenge_active'])
                                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-destination-verification="challenge">
                                    <p class="font-extrabold text-slate-950">Enter the code we sent</p>
                                    <p class="mt-1 text-sm text-slate-600">Sent to {{ $destinationVerification['masked_destination'] }}</p>
                                </div>

                                <form
                                    class="mt-5 grid gap-4"
                                    method="POST"
                                    action="{{ route('scheduling.public.offers.verification.verify', ['offerId' => $offerSummary['offer_id']], false) }}"
                                >
                                    @csrf
                                    <label class="grid gap-2 text-sm font-bold text-slate-800" for="verification_code">
                                        Verification code
                                        <input
                                            class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                            id="verification_code"
                                            name="code"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            maxlength="{{ $destinationVerification['code_max_length'] }}"
                                            required
                                            autofocus
                                        >
                                        @error('code')
                                            <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
                                        @enderror
                                    </label>
                                    <div class="flex flex-wrap gap-3">
                                        <x-public-surface.button type="submit">Confirm code</x-public-surface.button>
                                    </div>
                                </form>

                                <form
                                    class="mt-3"
                                    method="POST"
                                    action="{{ route('scheduling.public.offers.verification.resend', ['offerId' => $offerSummary['offer_id']], false) }}"
                                >
                                    @csrf
                                    <x-public-surface.button type="submit" variant="secondary">Send another code</x-public-surface.button>
                                </form>

                                <details class="mt-6 border-t border-slate-200 pt-5">
                                    <summary class="cursor-pointer text-sm font-extrabold text-slate-700">Use a different email or phone number</summary>
                                    @include('scheduling.public.partials.verification-form', ['suffix' => '-change', 'buttonLabel' => 'Send new code'])
                                </details>
                            @else
                                <div data-destination-verification="required">
                                    @include('scheduling.public.partials.verification-form', ['suffix' => '', 'buttonLabel' => 'Send code'])
                                </div>
                            @endif
                        @else
                            <form
                                class="mt-6 flex justify-end"
                                method="POST"
                                action="{{ route('scheduling.public.offers.hold', ['offerId' => $offerSummary['offer_id']], false) }}"
                            >
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $holdIdempotencyKey) }}">
                                <x-public-surface.button type="submit">Continue</x-public-surface.button>
                            </form>
                        @endif
                    @else
                        <div class="mt-6">
                            <x-public-surface.button
                                :href="route('scheduling.public.services.show', array_filter(['serviceKey' => $offerSummary['service_key'], 'date' => $offerSummary['is_range'] ? null : $offerSummary['date']]), false)"
                            >
                                View available times
                            </x-public-surface.button>
                        </div>
                    @endif
                </x-public-surface.card>
            </div>
        @elseif(!$selectedService)
            <div class="mx-auto max-w-4xl">
                <div class="mx-auto max-w-3xl text-center">
                    <h1 class="text-4xl font-black tracking-tight text-slate-950 sm:text-5xl">{{ $publicPresentation['heading'] }}</h1>
                    <p class="mx-auto mt-4 max-w-2xl text-lg leading-8 text-slate-600">{{ $publicPresentation['intro'] }}</p>
                </div>

                <section class="mt-10 grid gap-4 sm:grid-cols-2" aria-label="Available services">
                    @forelse($services as $service)
                        <a
                            class="group block rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-6 text-slate-950 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
                            href="{{ route('scheduling.public.services.show', ['serviceKey' => $service->key], false) }}"
                            data-report-service-selected
                            data-service-key="{{ $service->key }}"
                        >
                            <span class="block text-lg font-extrabold tracking-tight">{{ $service->name }}</span>
                            <span class="mt-2 block text-sm leading-6 text-slate-600">
                                {{ $service->usesRangeDuration() ? 'Choose your start and end time' : $service->duration_minutes.' minutes' }}
                                @if($service->description)
                                    <span class="block pt-1">{{ $service->description }}</span>
                                @endif
                            </span>
                            <span class="mt-5 inline-flex items-center text-sm font-extrabold text-[var(--public-primary)]">Choose this service <span class="ml-1 transition group-hover:translate-x-1" aria-hidden="true">→</span></span>
                        </a>
                    @empty
                        <div
                            class="rounded-[2rem] border border-dashed border-slate-300 bg-white p-8 text-center text-sm leading-6 text-slate-600 sm:col-span-2"
                            data-booking-empty-state="services"
                        >
                            No public services are available. Please check back later.
                        </div>
                    @endforelse
                </section>
            </div>
        @else
            <div class="mx-auto max-w-4xl">
                <a
                    class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-[var(--public-primary)]"
                    href="{{ route('scheduling.public.index', [], false) }}"
                >
                    <span aria-hidden="true">←</span> All services
                </a>

                <x-public-surface.card>
                    <div class="max-w-3xl">
                        <h1 class="text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ $selectedService->name }}</h1>
                        @if($selectedService->description)
                            <p class="mt-3 text-base leading-7 text-slate-600">{{ $selectedService->description }}</p>
                        @endif
                    </div>

                    @if($requiresCustomerSitePreparation)
                        <div class="mt-7 border-t border-slate-200 pt-7">
                            <h2 class="text-xl font-black tracking-tight text-slate-950">Where should we meet?</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Enter the address where this appointment will take place.</p>
                            @include('scheduling.public.partials.address-form', [
                                'preparedLocation' => null,
                                'buttonLabel' => 'Show available times',
                            ])
                        </div>
                    @else
                        @if($preparedLocation)
                            <div class="mt-7">
                                @include('scheduling.public.partials.location-details', [
                                    'location' => $preparedLocation,
                                ])
                            </div>
                        @endif

                        @if($selectedService->usesRangeDuration())
                            <form
                                class="mt-7 grid gap-5 border-t border-slate-200 pt-7"
                                method="POST"
                                action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}"
                            >
                                @csrf
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <label class="grid gap-2 text-sm font-bold text-slate-800" for="range_starts_at">
                                        Start
                                        <input
                                            class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                            id="range_starts_at"
                                            name="range_starts_at"
                                            type="datetime-local"
                                            value="{{ old('range_starts_at') }}"
                                            min="{{ now($displayTimezone)->format('Y-m-d\TH:i') }}"
                                            max="{{ $maximumDate?->format('Y-m-d\T23:59') }}"
                                            step="{{ max(60, (int) $selectedService->slot_interval_minutes * 60) }}"
                                            required
                                        >
                                    </label>
                                    <label class="grid gap-2 text-sm font-bold text-slate-800" for="range_ends_at">
                                        End
                                        <input
                                            class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                            id="range_ends_at"
                                            name="range_ends_at"
                                            type="datetime-local"
                                            value="{{ old('range_ends_at') }}"
                                            max="{{ $maximumDate?->format('Y-m-d\T23:59') }}"
                                            step="{{ max(60, (int) $selectedService->slot_interval_minutes * 60) }}"
                                            required
                                        >
                                    </label>
                                </div>
                                <p class="text-sm leading-6 text-slate-500">Choose between {{ $selectedService->minimumDurationMinutes() }} and {{ $selectedService->maximumDurationMinutes() }} minutes. Times use {{ str_replace('_', ' ', $displayTimezone) }}.</p>
                                <div class="flex justify-end">
                                    <x-public-surface.button type="submit">Check these dates</x-public-surface.button>
                                </div>
                            </form>
                        @else
                            <div class="mt-7 border-t border-slate-200 pt-7">
                                <form
                                    class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                                    method="GET"
                                    action="{{ route('scheduling.public.services.show', ['serviceKey' => $selectedService->key], false) }}"
                                >
                                    <label class="grid gap-2 text-sm font-bold text-slate-800" for="date">
                                        Date
                                        <input
                                            class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                                            id="date"
                                            name="date"
                                            type="date"
                                            value="{{ old('date', $selectedDate?->format('Y-m-d')) }}"
                                            min="{{ now($displayTimezone)->format('Y-m-d') }}"
                                            max="{{ $maximumDate?->format('Y-m-d') }}"
                                            required
                                        >
                                    </label>
                                    <x-public-surface.button type="submit" variant="secondary">View times</x-public-surface.button>
                                </form>

                                <p class="mt-3 text-sm leading-6 text-slate-500">
                                    Times shown in {{ str_replace('_', ' ', $displayTimezone) }}. Choose a start time to see the complete appointment time.
                                </p>

                                @if($availableTimePeriods !== [])
                                    <form
                                        class="mt-6"
                                        method="POST"
                                        action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}"
                                        data-time-selector
                                    >
                                        @csrf

                                        <div
                                            class="grid grid-cols-2 gap-2 sm:inline-grid sm:grid-flow-col sm:auto-cols-fr"
                                            role="tablist"
                                            aria-label="Time of day"
                                            data-day-period-tabs
                                        >
                                            @foreach($availableTimePeriods as $period)
                                                <button
                                                    type="button"
                                                    class="min-h-11 rounded-full border border-slate-300 bg-white px-5 py-2.5 text-sm font-extrabold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--public-accent)] aria-selected:border-[var(--public-primary)] aria-selected:bg-[var(--public-primary)] aria-selected:text-white"
                                                    id="booking-period-tab-{{ $period['key'] }}"
                                                    role="tab"
                                                    aria-controls="booking-period-panel-{{ $period['key'] }}"
                                                    aria-selected="false"
                                                    data-day-period-tab="{{ $period['key'] }}"
                                                >
                                                    {{ $period['label'] }}
                                                </button>
                                            @endforeach
                                        </div>

                                        <div class="mt-5" aria-live="polite">
                                            @foreach($availableTimePeriods as $period)
                                                <section
                                                    id="booking-period-panel-{{ $period['key'] }}"
                                                    role="tabpanel"
                                                    aria-labelledby="booking-period-tab-{{ $period['key'] }}"
                                                    data-day-period-panel="{{ $period['key'] }}"
                                                >
                                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                                        @foreach($period['times'] as $time)
                                                            <div class="relative">
                                                                <input
                                                                    class="peer sr-only"
                                                                    id="booking-time-{{ $loop->parent->index }}-{{ $loop->index }}"
                                                                    type="radio"
                                                                    name="starts_at"
                                                                    value="{{ $time['starts_at'] }}"
                                                                    data-time-option-input
                                                                    data-day-period="{{ $period['key'] }}"
                                                                    @checked(old('starts_at') === $time['starts_at'])
                                                                    required
                                                                >
                                                                <label
                                                                    class="flex min-h-12 cursor-pointer items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-3 text-center text-sm font-extrabold text-[var(--public-primary)] transition hover:border-[var(--public-primary)] hover:bg-slate-50 peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--public-accent)] data-[selected=true]:border-[var(--public-primary)] data-[selected=true]:bg-[var(--public-primary)] data-[selected=true]:text-white"
                                                                    for="booking-time-{{ $loop->parent->index }}-{{ $loop->index }}"
                                                                    data-time-option
                                                                    data-day-period="{{ $period['key'] }}"
                                                                    data-starts-at="{{ $time['starts_at'] }}"
                                                                    data-start-label="{{ $time['start_label'] }}"
                                                                    data-full-label="{{ $time['full_label'] }}"
                                                                    data-selected="false"
                                                                >
                                                                    <span data-time-option-label>{{ $time['start_label'] }}</span>
                                                                </label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </section>
                                            @endforeach
                                        </div>

                                        <div class="mt-6 flex justify-end border-t border-slate-200 pt-5">
                                            <x-public-surface.button type="submit" data-time-continue>
                                                Continue
                                            </x-public-surface.button>
                                        </div>
                                    </form>
                                @else
                                    <div
                                        class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm leading-6 text-slate-600"
                                        data-booking-empty-state="times"
                                    >
                                        No appointment times are available on this date. Try another date.
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($preparedLocation && $preparedLocation['type'] === 'customer_site')
                            <details class="mt-7 border-t border-slate-200 pt-5">
                                <summary class="cursor-pointer text-sm font-extrabold text-slate-700">Change service address</summary>
                                @include('scheduling.public.partials.address-form', [
                                    'buttonLabel' => 'Update address',
                                ])
                            </details>
                        @endif
                    @endif
                </x-public-surface.card>
            </div>
        @endif
    </div>

    <script type="application/json" id="scheduling-public-booking-config">@json($reportingConfig)</script>


</x-layouts.public-surface>