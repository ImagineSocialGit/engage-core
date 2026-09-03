<x-layouts.public-surface
    :title="$publicPresentation['heading']"
    :robots="$publicRobots"
    :primary-color="$publicPresentation['primary_color']"
    :accent-color="$publicPresentation['accent_color']"
    :surface-color="$publicPresentation['surface_color']"
    :background-color="$publicPresentation['background_color']"
>
    <x-slot:header>
        <div class="{{ $publicPresentation['style']['header_inner'] }}">
            <a
                href="{{ route('scheduling.public.index', [], false) }}"
                class="{{ $publicPresentation['style']['brand_link'] }}"
                aria-label="{{ $publicPresentation['brand_name'] }} appointments"
            >
                @if($publicPresentation['logo'])
                    <x-ui.image
                        :path="$publicPresentation['logo']"
                        :alt="$publicPresentation['brand_name']"
                        sizes="96px"
                        class="{{ $publicPresentation['style']['brand_logo'] }}"
                        :placeholder="false"
                    />
                @elseif($publicPresentation['logo_url'])
                    <img
                        src="{{ $publicPresentation['logo_url'] }}"
                        alt="{{ $publicPresentation['brand_name'] }}"
                        class="{{ $publicPresentation['style']['brand_logo'] }}"
                    >
                @else
                    <span class="{{ $publicPresentation['style']['brand_text'] }}">
                        {{ $publicPresentation['brand_name'] }}
                    </span>
                @endif
            </a>
        </div>
    </x-slot:header>

    <x-slot:footer>
        <div class="{{ $publicPresentation['style']['footer_inner'] }}">
            {{ $publicPresentation['brand_name'] }}
        </div>
    </x-slot:footer>

    <div
        class="{{ $publicPresentation['style']['page'] }}"
        data-scheduling-public-booking
        data-scheduling-public-style-contract="1"
        data-booking-state="{{ $pageState }}"
    >
        @if($errors->any())
            <div
                class="{{ $publicPresentation['style']['error_banner'] }}"
                role="alert"
                aria-labelledby="booking-errors-title"
            >
                <p id="booking-errors-title" class="font-extrabold">Please check the highlighted information and try again.</p>
            </div>
        @endif

        @if($holdSummary)
            <div class="{{ $publicPresentation['style']['state_width'] }}">
                <x-public-surface.card aria-live="polite">
                    @if($holdSummary['status'] === 'active')
                        <span class="{{ $publicPresentation['style']['state_badge'] }}">Time reserved</span>
                        <h1 class="{{ $publicPresentation['style']['state_title'] }}">Complete your booking</h1>
                        <p class="{{ $publicPresentation['style']['state_body'] }}">This time is held while you add your contact details.</p>
                    @elseif($holdSummary['status'] === 'converted' && $holdSummary['confirmation_pending'])
                        <span class="{{ $publicPresentation['style']['state_badge'] }}">Request received</span>
                        <h1 class="{{ $publicPresentation['style']['state_title'] }}">We received your request</h1>
                        <p class="{{ $publicPresentation['style']['state_body'] }}">The team will review it and contact you using the information you provided.</p>
                    @elseif($holdSummary['status'] === 'converted')
                        <span class="{{ $publicPresentation['style']['state_badge'] }}">Appointment booked</span>
                        <h1 class="{{ $publicPresentation['style']['state_title'] }}">You’re all set</h1>
                        <p class="{{ $publicPresentation['style']['state_body'] }}">Your appointment has been booked. Save the details below.</p>
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-slate-600">Time no longer available</span>
                        <h1 class="{{ $publicPresentation['style']['state_title'] }}">Choose another time</h1>
                        <p class="{{ $publicPresentation['style']['state_body'] }}">This reservation expired before the booking was completed.</p>
                    @endif

                    @include('scheduling.public.partials.appointment-summary', ['summary' => $holdSummary, 'publicPresentation' => $publicPresentation])

                    @if($holdSummary['status'] === 'active')
                        <p
                            class="{{ $publicPresentation['style']['countdown'] }}"
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
                                <label class="{{ $publicPresentation['style']['field_label'] }}" for="first_name">
                                    First name
                                    <input
                                        class="{{ $publicPresentation['style']['input'] }}"
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

                                <label class="{{ $publicPresentation['style']['field_label'] }}" for="last_name">
                                    Last name
                                    <input
                                        class="{{ $publicPresentation['style']['input'] }}"
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

                                <label class="{{ $publicPresentation['style']['field_label'] }}" for="email">
                                    Email address
                                    <input
                                        class="{{ $publicPresentation['style']['input'] }} read-only:bg-slate-100"
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

                                <label class="{{ $publicPresentation['style']['field_label'] }}" for="phone">
                                    <span>
                                        Phone number
                                        @if(($holdSummary['location_type'] ?? null) !== 'phone')
                                            <span class="font-medium text-slate-500">(optional)</span>
                                        @endif
                                    </span>
                                    <input
                                        class="{{ $publicPresentation['style']['input'] }} read-only:bg-slate-100"
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
            <div class="{{ $publicPresentation['style']['state_width'] }}">
                <a
                    class="{{ $publicPresentation['style']['back_link'] }}"
                    href="{{ route('scheduling.public.services.show', array_filter(['serviceKey' => $offerSummary['service_key'], 'date' => $offerSummary['is_range'] ? null : $offerSummary['date']]), false) }}"
                >
                    <span aria-hidden="true">←</span> Change time
                </a>

                <x-public-surface.card aria-live="polite">
                    @if($offerSummary['status'] === 'active')
                        <span class="{{ $publicPresentation['style']['state_badge'] }}">Selected time</span>
                        @if($destinationVerification['required'])
                            <h1 class="{{ $publicPresentation['style']['state_title'] }}">Confirm it’s you</h1>
                            <p class="{{ $publicPresentation['style']['state_body'] }}">We’ll send a short code before reserving this appointment.</p>
                        @else
                            <h1 class="{{ $publicPresentation['style']['state_title'] }}">Review your appointment</h1>
                            <p class="{{ $publicPresentation['style']['state_body'] }}">Continue to reserve this time while you add your contact details.</p>
                        @endif
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-slate-600">Selection expired</span>
                        <h1 class="{{ $publicPresentation['style']['state_title'] }}">This time needs to be selected again</h1>
                        <p class="{{ $publicPresentation['style']['state_body'] }}">No appointment was reserved.</p>
                    @endif

                    @include('scheduling.public.partials.appointment-summary', ['summary' => $offerSummary, 'publicPresentation' => $publicPresentation])

                    @if($offerSummary['status'] === 'active')
                        <p
                            class="{{ $publicPresentation['style']['countdown'] }}"
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
                                    <label class="{{ $publicPresentation['style']['field_label'] }}" for="verification_code">
                                        Verification code
                                        <input
                                            class="{{ $publicPresentation['style']['input'] }}"
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
                                    @include('scheduling.public.partials.verification-form', ['suffix' => '-change', 'buttonLabel' => 'Send new code', 'publicPresentation' => $publicPresentation])
                                </details>
                            @else
                                <div data-destination-verification="required">
                                    @include('scheduling.public.partials.verification-form', ['suffix' => '', 'buttonLabel' => 'Send code', 'publicPresentation' => $publicPresentation])
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
            <div class="{{ $publicPresentation['style']['catalog_width'] }}">
                <div class="{{ $publicPresentation['style']['catalog_intro'] }}">
                    <h1 class="{{ $publicPresentation['style']['catalog_title'] }}">{{ $publicPresentation['heading'] }}</h1>
                    <p class="{{ $publicPresentation['style']['catalog_body'] }}">{{ $publicPresentation['intro'] }}</p>
                </div>

                <section class="{{ $publicPresentation['style']['catalog_grid'] }}" aria-label="Available services">
                    @forelse($services as $service)
                        <a
                            class="{{ $publicPresentation['style']['service_card'] }}"
                            href="{{ route('scheduling.public.services.show', ['serviceKey' => $service->key], false) }}"
                            data-report-service-selected
                            data-service-key="{{ $service->key }}"
                        >
                            <span class="{{ $publicPresentation['style']['service_card_title'] }}">{{ $service->name }}</span>
                            <span class="{{ $publicPresentation['style']['service_card_body'] }}">
                                {{ $service->usesRangeDuration() ? 'Choose your start and end time' : $service->duration_minutes.' minutes' }}
                                @if($service->description)
                                    <span class="block pt-1">{{ $service->description }}</span>
                                @endif
                            </span>
                            <span class="{{ $publicPresentation['style']['service_card_cta'] }}">Choose this service <span class="ml-1 transition group-hover:translate-x-1" aria-hidden="true">→</span></span>
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
            <div class="{{ $publicPresentation['style']['service_width'] }}">
                <a
                    class="{{ $publicPresentation['style']['back_link'] }}"
                    href="{{ route('scheduling.public.index', [], false) }}"
                >
                    <span aria-hidden="true">←</span> All services
                </a>

                <x-public-surface.card>
                    <div class="{{ $publicPresentation['style']['service_header'] }}">
                        <h1 class="{{ $publicPresentation['style']['service_title'] }}">{{ $selectedService->name }}</h1>
                        @if($selectedService->description)
                            <p class="{{ $publicPresentation['style']['service_description'] }}">{{ $selectedService->description }}</p>
                        @endif
                    </div>

                    @if($requiresCustomerSitePreparation)
                        <div class="{{ $publicPresentation['style']['section'] }}">
                            <h2 class="{{ $publicPresentation['style']['section_title'] }}">Where should we meet?</h2>
                            <p class="{{ $publicPresentation['style']['section_body'] }}">Enter the address where this appointment will take place.</p>
                            @include('scheduling.public.partials.address-form', [
                                'publicPresentation' => $publicPresentation,
                                'preparedLocation' => null,
                                'buttonLabel' => 'Show available times',
                            ])
                        </div>
                    @else
                        @if($preparedLocation)
                            <div class="mt-7">
                                @include('scheduling.public.partials.location-details', [
                                    'publicPresentation' => $publicPresentation,
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
                                    <label class="{{ $publicPresentation['style']['field_label'] }}" for="range_starts_at">
                                        Start
                                        <input
                                            class="{{ $publicPresentation['style']['input'] }}"
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
                                    <label class="{{ $publicPresentation['style']['field_label'] }}" for="range_ends_at">
                                        End
                                        <input
                                            class="{{ $publicPresentation['style']['input'] }}"
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
                            <div class="{{ $publicPresentation['style']['section'] }}">
                                <form
                                    class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                                    method="GET"
                                    action="{{ route('scheduling.public.services.show', ['serviceKey' => $selectedService->key], false) }}"
                                >
                                    <label class="{{ $publicPresentation['style']['field_label'] }}" for="date">
                                        Date
                                        <input
                                            class="{{ $publicPresentation['style']['input'] }}"
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

                                <p class="mt-3 {{ $publicPresentation['style']['helper_text'] }}">
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
                                            class="{{ $publicPresentation['style']['day_period_tabs'] }}"
                                            role="tablist"
                                            aria-label="Time of day"
                                            data-day-period-tabs
                                        >
                                            @foreach($availableTimePeriods as $period)
                                                <button
                                                    type="button"
                                                    class="{{ $publicPresentation['style']['day_period_tab'] }}"
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

                                        <div class="{{ $publicPresentation['style']['time_panel'] }}" aria-live="polite">
                                            @foreach($availableTimePeriods as $period)
                                                <section
                                                    id="booking-period-panel-{{ $period['key'] }}"
                                                    role="tabpanel"
                                                    aria-labelledby="booking-period-tab-{{ $period['key'] }}"
                                                    data-day-period-panel="{{ $period['key'] }}"
                                                >
                                                    <div class="{{ $publicPresentation['style']['time_grid'] }}">
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
                                                                    class="{{ $publicPresentation['style']['time_option'] }}"
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

                                        <div class="{{ $publicPresentation['style']['continue_row'] }}">
                                            <x-public-surface.button type="submit" data-time-continue>
                                                Continue
                                            </x-public-surface.button>
                                        </div>
                                    </form>
                                @else
                                    <div
                                        class="{{ $publicPresentation['style']['empty_state'] }}"
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
                                    'publicPresentation' => $publicPresentation,
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