@php
    $pageState = match (true) {
        (bool) $holdSummary && in_array($holdSummary['status'], ['converted'], true) => 'confirmation',
        (bool) $holdSummary && $holdSummary['status'] === 'active' => 'details',
        (bool) $holdSummary => 'expired',
        (bool) $offerSummary && $offerSummary['status'] !== 'active' => 'expired',
        (bool) $offerSummary && $destinationVerification['required'] => 'verification',
        (bool) $offerSummary => 'offer',
        (bool) $selectedService && $requiresCustomerSitePreparation => 'address',
        (bool) $selectedService => 'availability',
        default => 'catalog',
    };
    $reportingServiceKey = $holdSummary['service_key']
        ?? $offerSummary['service_key']
        ?? $selectedService?->key;
    $availabilityState = $pageState === 'address'
        ? 'address_required'
        : ($pageState === 'availability'
            ? ($selectedService?->usesRangeDuration()
                ? 'range'
                : ($availableTimes === [] ? 'empty' : 'available'))
            : null);
    $reportingConfig = [
        'reportingEnabled' => $publicPresentation['reporting_enabled'],
        'pageRevision' => $publicPresentation['page_revision'],
        'state' => $pageState,
        'serviceKey' => $reportingServiceKey,
        'availabilityState' => $availabilityState,
        'verificationCompletedChannel' => $destinationVerification['verified']
            ? $destinationVerification['verified_channel']
            : null,
        'validationFields' => array_values(array_slice($errors->keys(), 0, 16)),
    ];
    $locationSummary = $holdSummary ?: $offerSummary;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $holdSummary || $offerSummary ? 'noindex,nofollow' : 'index,follow' }}">
    <title>{{ $publicPresentation['heading'] }}</title>
    @vite(['resources/js/app.js'])
    <style>
        :root {
            color-scheme: light;
            --booking-primary: {{ $publicPresentation['primary_color'] }};
            --booking-accent: {{ $publicPresentation['accent_color'] }};
            --booking-surface: {{ $publicPresentation['surface_color'] }};
            --booking-background: {{ $publicPresentation['background_color'] }};
            --booking-text: #172026;
            --booking-muted: #617079;
            --booking-border: #dce3e7;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--booking-background); color: var(--booking-text); }
        a { color: var(--booking-primary); }
        button, input, select { font: inherit; }
        button, .button-link {
            appearance: none; border: 0; border-radius: 10px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 44px; padding: .72rem 1rem; background: var(--booking-primary);
            color: #fff; font-weight: 750; text-decoration: none;
        }
        button:hover, .button-link:hover { filter: brightness(.92); }
        button:focus-visible, .button-link:focus-visible, input:focus-visible,
        select:focus-visible, summary:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--booking-accent) 32%, transparent);
            outline-offset: 2px;
        }
        .button-secondary { background: #edf1f2; color: #24343a; }
        .shell { width: min(760px, calc(100% - 2rem)); margin: 0 auto; padding: 2rem 0 4rem; }
        .brand { display: flex; align-items: center; gap: .75rem; min-height: 38px; margin-bottom: 2rem; color: #26363c; font-weight: 750; }
        .brand img { display: block; max-width: 180px; max-height: 48px; object-fit: contain; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { font-size: clamp(2rem, 7vw, 3rem); line-height: 1.05; letter-spacing: -.035em; margin-bottom: .65rem; }
        h2 { font-size: 1.45rem; letter-spacing: -.015em; margin-bottom: .4rem; }
        h3 { font-size: 1rem; margin-bottom: .65rem; }
        .intro { color: var(--booking-muted); font-size: 1.05rem; line-height: 1.55; max-width: 38rem; margin-bottom: 1.6rem; }
        .card { background: var(--booking-surface); border: 1px solid var(--booking-border); border-radius: 18px; box-shadow: 0 14px 34px rgba(28, 43, 50, .07); }
        .panel { padding: clamp(1.1rem, 4vw, 1.6rem); }
        .back { display: inline-flex; align-items: center; gap: .3rem; margin-bottom: 1.1rem; color: var(--booking-muted); font-weight: 700; text-decoration: none; }
        .back:hover { color: var(--booking-primary); }
        .services { display: grid; gap: .75rem; }
        .service-link { display: block; padding: 1.05rem 1.1rem; border: 1px solid var(--booking-border); border-radius: 14px; color: inherit; text-decoration: none; background: var(--booking-surface); }
        .service-link:hover { border-color: var(--booking-accent); box-shadow: 0 8px 20px rgba(28, 43, 50, .06); }
        .service-link strong { display: block; margin-bottom: .25rem; }
        .service-link span { display: block; color: var(--booking-muted); font-size: .92rem; line-height: 1.4; }
        .status { display: inline-flex; margin-bottom: .8rem; padding: .3rem .55rem; border-radius: 999px; background: color-mix(in srgb, var(--booking-accent) 13%, white); color: var(--booking-primary); font-size: .75rem; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; margin: 1.15rem 0; }
        .summary div { padding: .85rem; border-radius: 12px; background: #f6f8f9; }
        .summary .wide { grid-column: 1 / -1; }
        .summary dt { color: var(--booking-muted); font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .25rem; }
        .summary dd { margin: 0; font-weight: 700; line-height: 1.4; }
        .notice { padding: .9rem 1rem; border-radius: 12px; background: color-mix(in srgb, var(--booking-accent) 9%, white); margin: 1rem 0; line-height: 1.45; }
        .notice strong { display: block; margin-bottom: .15rem; }
        .error-summary { padding: 1rem; border: 1px solid #efb4b4; border-radius: 12px; background: #fff5f5; color: #8d1b1b; margin-bottom: 1rem; }
        .error-summary strong { display: block; margin-bottom: .35rem; }
        .error-summary ul { margin: 0; padding-left: 1.2rem; }
        .error { color: #a32121; font-size: .88rem; font-weight: 500; }
        .muted { color: var(--booking-muted); }
        .small { font-size: .84rem; line-height: 1.45; }
        .form { display: grid; gap: 1rem; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
        .field { display: grid; gap: .38rem; font-size: .92rem; font-weight: 700; }
        .field-wide { grid-column: 1 / -1; }
        input, select { width: 100%; min-height: 44px; border: 1px solid #c8d2d7; border-radius: 10px; padding: .7rem .78rem; background: #fff; color: inherit; }
        input[readonly] { background: #f3f6f7; color: #435159; }
        .verified-note { color: var(--booking-primary); font-size: .78rem; font-weight: 700; }
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
        .date-row { display: grid; grid-template-columns: 1fr auto; gap: .65rem; align-items: end; margin: 1.1rem 0; }
        .periods { display: grid; gap: 1.25rem; margin-top: 1.2rem; }
        .time-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .55rem; }
        .time-option { border: 1px solid #cfe0dd; border-radius: 11px; background: #f1f8f7; overflow: hidden; }
        .time-option summary { cursor: pointer; list-style: none; padding: .72rem .55rem; color: var(--booking-primary); font-weight: 800; text-align: center; }
        .time-option summary::-webkit-details-marker { display: none; }
        .time-option[open] { grid-column: span 2; border-color: var(--booking-accent); background: #fff; box-shadow: 0 7px 18px rgba(28, 43, 50, .07); }
        .time-option[open] summary { padding-bottom: .35rem; }
        .time-confirm { padding: 0 .75rem .75rem; text-align: center; }
        .time-confirm p { margin-bottom: .6rem; font-size: .86rem; color: var(--booking-muted); }
        .empty { padding: 1rem; border: 1px dashed #cbd5d9; border-radius: 12px; color: var(--booking-muted); }
        .countdown { color: #4e5d64; font-size: .9rem; font-weight: 700; }
        .consent { max-width: 40rem; margin: .2rem 0 0; color: #69777e; font-size: .75rem; line-height: 1.45; }
        details.change { margin-top: 1rem; border-top: 1px solid #e3e8eb; padding-top: 1rem; }
        details.change > summary { cursor: pointer; font-weight: 700; }
        @media (max-width: 620px) {
            .shell { width: min(100% - 1rem, 760px); padding-top: 1rem; }
            .brand { margin: .5rem .5rem 1.3rem; }
            .fields, .summary { grid-template-columns: 1fr; }
            .summary .wide { grid-column: auto; }
            .date-row { grid-template-columns: 1fr; }
            .time-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .time-option[open] { grid-column: 1 / -1; }
        }
    </style>
</head>
<body>
<main class="shell">
    <div class="brand">
        @if($publicPresentation['logo_url'])
            <img src="{{ $publicPresentation['logo_url'] }}" alt="{{ $publicPresentation['brand_name'] }}">
        @else
            <span>{{ $publicPresentation['brand_name'] }}</span>
        @endif
    </div>

    @if($errors->any())
        <div class="error-summary" role="alert" aria-labelledby="booking-errors-title">
            <strong id="booking-errors-title">Please check the following:</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($holdSummary)
        <section class="card panel" aria-live="polite">
            @if($holdSummary['status'] === 'active')
                <span class="status">Time reserved</span>
                <h1>Complete your booking</h1>
                <p class="intro">This time is held for you while you add your contact details.</p>
            @elseif($holdSummary['status'] === 'converted' && $holdSummary['confirmation_pending'])
                <span class="status">Request received</span>
                <h1>We received your request</h1>
                <p class="intro">The team will review it and contact you using the information you provided.</p>
            @elseif($holdSummary['status'] === 'converted')
                <span class="status">Appointment booked</span>
                <h1>You’re all set</h1>
                <p class="intro">Your appointment has been booked. Save the details below.</p>
            @else
                <span class="status">Time no longer available</span>
                <h1>Choose another time</h1>
                <p class="intro">This reservation expired before the booking was completed.</p>
            @endif

            @include('scheduling.public.partials.appointment-summary', ['summary' => $holdSummary])

            @if($holdSummary['status'] === 'active')
                <p class="countdown" data-countdown data-expires-at="{{ $holdSummary['expires_at'] }}" data-expired-message="This reservation has expired. Refresh to choose another time.">
                    Reserved for {{ max(1, (int) ceil($holdSummary['remaining_seconds'] / 60)) }} more minute(s).
                </p>

                <form class="form" method="POST" action="{{ route('scheduling.public.holds.complete', ['holdId' => $holdSummary['hold_id']], false) }}" data-booking-details-form>
                    @csrf
                    <input type="hidden" name="public_submission_attempt_id" value="">
                    <div class="fields">
                        <label class="field" for="first_name">
                            First name
                            <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" autocomplete="given-name" maxlength="120" required>
                            @error('first_name')<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <label class="field" for="last_name">
                            Last name
                            <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" autocomplete="family-name" maxlength="120" required>
                            @error('last_name')<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <label class="field" for="email">
                            Email address
                            <input id="email" name="email" type="email" value="{{ old('email', $contactPrefill['email']) }}" autocomplete="email" maxlength="255" @readonly($contactPrefill['verified_channel'] === 'email') required>
                            @if($contactPrefill['verified_channel'] === 'email')<span class="verified-note">Verified email</span>@endif
                            @error('email')<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <label class="field" for="phone">
                            Mobile phone <span class="muted">(optional)</span>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone', $contactPrefill['phone']) }}" autocomplete="tel" inputmode="tel" maxlength="24" placeholder="(555) 555-0123" data-phone-mask @readonly($contactPrefill['verified_channel'] === 'sms')>
                            @if($contactPrefill['verified_channel'] === 'sms')<span class="verified-note">Verified phone number</span>@endif
                            @error('phone')<span class="error">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <div class="actions"><button type="submit">Complete booking</button></div>
                    <p class="consent">{{ $publicPresentation['consent_text'] }}</p>
                </form>
            @elseif(!in_array($holdSummary['status'], ['converted'], true))
                <a class="button-link" href="{{ route('scheduling.public.services.show', array_filter(['serviceKey' => $holdSummary['service_key'], 'date' => $holdSummary['is_range'] ? null : $holdSummary['date']]), false) }}">View available times</a>
            @endif
        </section>
    @elseif($offerSummary)
        <section class="card panel" aria-live="polite">
            <a class="back" href="{{ route('scheduling.public.services.show', array_filter(['serviceKey' => $offerSummary['service_key'], 'date' => $offerSummary['is_range'] ? null : $offerSummary['date']]), false) }}">← Change time</a>

            @if($offerSummary['status'] === 'active')
                <span class="status">Selected time</span>
                @if($destinationVerification['required'])
                    <h1>Confirm it’s you</h1>
                    <p class="intro">We’ll send a short code before reserving this appointment.</p>
                @else
                    <h1>Review your appointment</h1>
                    <p class="intro">Continue to reserve this time while you add your contact details.</p>
                @endif
            @else
                <span class="status">Selection expired</span>
                <h1>This time needs to be selected again</h1>
                <p class="intro">No appointment was reserved.</p>
            @endif

            @include('scheduling.public.partials.appointment-summary', ['summary' => $offerSummary])

            @if($offerSummary['status'] === 'active')
                <p class="countdown" data-countdown data-expires-at="{{ $offerSummary['expires_at'] }}" data-expired-message="This selection has expired. Refresh to choose another time.">
                    {{ max(1, (int) ceil($offerSummary['remaining_seconds'] / 60)) }} minute(s) remaining.
                </p>

                @if($destinationVerification['required'])
                    @if($destinationVerification['verified'])
                        <div class="notice" data-destination-verification="verified">
                            <strong>Code confirmed</strong>
                            {{ $destinationVerification['masked_destination'] }}
                        </div>
                        <form method="POST" action="{{ route('scheduling.public.offers.hold', ['offerId' => $offerSummary['offer_id']], false) }}">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                            <button type="submit">Continue</button>
                        </form>
                    @elseif($destinationVerification['challenge_active'])
                        <div class="notice" data-destination-verification="challenge">
                            <strong>Enter the code we sent</strong>
                            Sent to {{ $destinationVerification['masked_destination'] }}
                        </div>
                        <form class="form" method="POST" action="{{ route('scheduling.public.offers.verification.verify', ['offerId' => $offerSummary['offer_id']], false) }}">
                            @csrf
                            <label class="field" for="verification_code">
                                Verification code
                                <input id="verification_code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ min(8, max(4, (int) config('scheduling.public.destination_verification.code_digits', 6))) }}" required autofocus>
                                @error('code')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <div class="actions"><button type="submit">Verify code</button></div>
                        </form>
                        <form method="POST" action="{{ route('scheduling.public.offers.verification.resend', ['offerId' => $offerSummary['offer_id']], false) }}" style="margin-top:.65rem">
                            @csrf
                            <button class="button-secondary" type="submit">Send another code</button>
                        </form>
                        <details class="change">
                            <summary>Use a different email or phone number</summary>
                            @include('scheduling.public.partials.verification-form', ['suffix' => '-change', 'buttonLabel' => 'Send new code'])
                        </details>
                    @else
                        <div data-destination-verification="required">
                            @include('scheduling.public.partials.verification-form', ['suffix' => '', 'buttonLabel' => 'Send code'])
                        </div>
                    @endif
                @else
                    <form method="POST" action="{{ route('scheduling.public.offers.hold', ['offerId' => $offerSummary['offer_id']], false) }}">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                        <button type="submit">Continue</button>
                    </form>
                @endif
            @else
                <a class="button-link" href="{{ route('scheduling.public.services.show', array_filter(['serviceKey' => $offerSummary['service_key'], 'date' => $offerSummary['is_range'] ? null : $offerSummary['date']]), false) }}">View available times</a>
            @endif
        </section>
    @elseif(!$selectedService)
        <h1>{{ $publicPresentation['heading'] }}</h1>
        <p class="intro">{{ $publicPresentation['intro'] }}</p>
        <section class="services" aria-label="Available services">
            @forelse($services as $service)
                <a class="service-link" href="{{ route('scheduling.public.services.show', ['serviceKey' => $service->key], false) }}" data-report-service-selected data-service-key="{{ $service->key }}">
                    <strong>{{ $service->name }}</strong>
                    <span>
                        {{ $service->usesRangeDuration() ? 'Choose your start and end time' : $service->duration_minutes.' minutes' }}
                        @if($service->description) · {{ $service->description }} @endif
                    </span>
                </a>
            @empty
                <div class="empty" data-booking-empty-state="services">No public services are available. Please check back later.</div>
            @endforelse
        </section>
    @else
        <a class="back" href="{{ route('scheduling.public.index', [], false) }}">← All services</a>
        <section class="card panel">
            <h1>{{ $selectedService->name }}</h1>
            @if($selectedService->description)<p class="intro">{{ $selectedService->description }}</p>@endif

            @if($requiresCustomerSitePreparation)
                <h2>Where should we meet?</h2>
                <p class="muted">Enter the address where this appointment will take place.</p>
                @include('scheduling.public.partials.address-form', ['preparedLocation' => null, 'buttonLabel' => 'Show available times'])
            @else
                @if($preparedLocation)
                    <div class="notice">
                        <strong>{{ $preparedLocation['label'] ?: match($preparedLocation['type']) { 'phone' => 'Phone appointment', 'virtual' => 'Online appointment', 'fixed' => 'Meeting location', 'customer_site' => 'Your location', default => 'Appointment location' } }}</strong>
                        @if($preparedLocation['formatted_address'])<span>{{ $preparedLocation['formatted_address'] }}</span>@endif
                        @if($preparedLocation['instructions'])<span>{{ $preparedLocation['instructions'] }}</span>@endif
                    </div>
                @endif

                @if($selectedService->usesRangeDuration())
                    <form class="form" method="POST" action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}">
                        @csrf
                        <div class="fields">
                            <label class="field" for="range_starts_at">Start<input id="range_starts_at" name="range_starts_at" type="datetime-local" value="{{ old('range_starts_at') }}" min="{{ now($displayTimezone)->format('Y-m-d\TH:i') }}" max="{{ $maximumDate?->format('Y-m-d\T23:59') }}" step="{{ max(60, (int) $selectedService->slot_interval_minutes * 60) }}" required></label>
                            <label class="field" for="range_ends_at">End<input id="range_ends_at" name="range_ends_at" type="datetime-local" value="{{ old('range_ends_at') }}" max="{{ $maximumDate?->format('Y-m-d\T23:59') }}" step="{{ max(60, (int) $selectedService->slot_interval_minutes * 60) }}" required></label>
                        </div>
                        <p class="small muted">Choose between {{ $selectedService->minimumDurationMinutes() }} and {{ $selectedService->maximumDurationMinutes() }} minutes. Times use {{ str_replace('_', ' ', $displayTimezone) }}.</p>
                        <div class="actions"><button type="submit">Check these dates</button></div>
                    </form>
                @else
                    <form class="date-row" method="GET" action="{{ route('scheduling.public.services.show', ['serviceKey' => $selectedService->key], false) }}">
                        <label class="field" for="date">Date<input id="date" name="date" type="date" value="{{ old('date', $selectedDate?->format('Y-m-d')) }}" min="{{ now($displayTimezone)->format('Y-m-d') }}" max="{{ $maximumDate?->format('Y-m-d') }}" required></label>
                        <button type="submit" class="button-secondary">View times</button>
                    </form>
                    <p class="small muted">Times shown in {{ str_replace('_', ' ', $displayTimezone) }}. Select a start time to see the full appointment time.</p>

                    @if($availableTimes !== [])
                        <div class="periods" aria-label="Available appointment start times">
                            @foreach(['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'] as $period => $label)
                                @php($periodTimes = collect($availableTimes)->where('period', $period)->values())
                                @if($periodTimes->isNotEmpty())
                                    <section>
                                        <h3>{{ $label }}</h3>
                                        <div class="time-grid">
                                            @foreach($periodTimes as $time)
                                                <details class="time-option" data-time-option data-day-period="{{ $period }}">
                                                    <summary>{{ $time['start_label'] }}</summary>
                                                    <div class="time-confirm">
                                                        <p>{{ $time['full_label'] }}</p>
                                                        <form method="POST" action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}">
                                                            @csrf
                                                            <input type="hidden" name="starts_at" value="{{ $time['starts_at'] }}">
                                                            <button type="submit">Continue</button>
                                                        </form>
                                                    </div>
                                                </details>
                                            @endforeach
                                        </div>
                                    </section>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="empty" data-booking-empty-state="times">No appointment times are available on this date. Try another date.</div>
                    @endif
                @endif

                @if($preparedLocation && $preparedLocation['type'] === 'customer_site')
                    <details class="change">
                        <summary>Change service address</summary>
                        @include('scheduling.public.partials.address-form', ['buttonLabel' => 'Update address'])
                    </details>
                @endif
            @endif
        </section>
    @endif
</main>

<script type="application/json" id="scheduling-public-booking-config">@json($reportingConfig)</script>

@if(($holdSummary && $holdSummary['status'] === 'active') || ($offerSummary && $offerSummary['status'] === 'active'))
    <script>
        (() => {
            const element = document.querySelector('[data-countdown]');
            if (!element) return;
            const expiresAt = Date.parse(element.dataset.expiresAt || '');
            if (!Number.isFinite(expiresAt)) return;
            const render = () => {
                const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
                if (remaining === 0) {
                    element.textContent = element.dataset.expiredMessage || 'This time has expired.';
                    return;
                }
                element.textContent = `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')} remaining.`;
                window.setTimeout(render, 1000);
            };
            render();
        })();
    </script>
@endif
</body>
</html>