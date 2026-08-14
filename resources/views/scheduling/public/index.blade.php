<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $holdSummary || $offerSummary ? 'noindex,nofollow' : 'index,follow' }}">
    <title>Schedule an appointment</title>
    <style>
        :root {
            color-scheme: light;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f7f8;
            color: #172026;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7f8; }
        main { width: min(1080px, calc(100% - 2rem)); margin: 0 auto; padding: 3rem 0 5rem; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { font-size: clamp(2rem, 5vw, 3.25rem); line-height: 1.02; margin-bottom: .7rem; }
        h2 { font-size: 1.35rem; margin-bottom: .4rem; }
        .intro { color: #56646d; max-width: 44rem; margin-bottom: 2rem; }
        .layout { display: grid; grid-template-columns: minmax(220px, .72fr) minmax(0, 1.45fr); gap: 1rem; align-items: start; }
        .card { background: white; border: 1px solid #dbe2e6; border-radius: 16px; box-shadow: 0 12px 30px rgba(28, 43, 50, .06); }
        .services { overflow: hidden; }
        .service-link { display: block; padding: 1rem 1.1rem; border-bottom: 1px solid #e7ecef; color: inherit; text-decoration: none; }
        .service-link:last-child { border-bottom: 0; }
        a.service-link:hover, .service-link[aria-current="page"] { background: #eef8f6; }
        .service-link strong { display: block; margin-bottom: .25rem; }
        .service-link span { display: block; color: #65737b; font-size: .92rem; line-height: 1.35; }
        .workspace { padding: 1.35rem; }
        .step { display: inline-flex; align-items: center; gap: .45rem; color: #39776f; font-size: .78rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-bottom: .65rem; }
        .muted { color: #66747c; }
        .notice { padding: .9rem 1rem; border-radius: 12px; background: #f1f7f6; margin-bottom: 1rem; }
        .error { color: #a32121; font-size: .9rem; margin-top: .4rem; }
        .booking-form, .date-form { display: grid; gap: 1rem; }
        .booking-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
        .booking-field, .date-form label { display: grid; gap: .35rem; font-weight: 650; font-size: .92rem; }
        input { width: 100%; border: 1px solid #c8d2d7; border-radius: 10px; padding: .72rem .78rem; font: inherit; background: white; }
        input:focus { outline: 3px solid rgba(36, 125, 113, .16); border-color: #247d71; }
        button, .button-link { appearance: none; border: 0; border-radius: 10px; background: #176f65; color: white; padding: .75rem 1rem; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        button:hover, .button-link:hover { background: #105d55; }
        .button-secondary { background: #e8efef; color: #25363b; }
        .button-secondary:hover { background: #dce6e6; }
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
        .times { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .65rem; margin-top: 1rem; }
        .time-form { margin: 0; }
        .time { width: 100%; background: #eef7f5; color: #176f65; border: 1px solid #cfe3df; }
        .time:hover { background: #dff0ec; }
        .empty { padding: 1rem; border: 1px dashed #cbd5d9; border-radius: 12px; color: #65737b; margin-top: 1rem; }
        .summary-card { padding: 1.35rem; max-width: 720px; }
        .status { display: inline-block; margin-bottom: .65rem; padding: .3rem .55rem; border-radius: 999px; background: #e7f4f1; color: #176f65; font-weight: 750; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; margin: 1.25rem 0; }
        .summary div { padding: .85rem; border-radius: 12px; background: #f7f9fa; }
        .summary dt { color: #67757c; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .25rem; }
        .summary dd { margin: 0; font-weight: 650; }
        .countdown { color: #4e5d64; font-weight: 650; }
        details { margin-top: 1rem; border-top: 1px solid #e3e8eb; padding-top: 1rem; }
        summary { cursor: pointer; font-weight: 700; }
        .footer-note { margin-top: 1rem; color: #718087; font-size: .88rem; }
        @media (max-width: 760px) {
            main { width: min(100% - 1rem, 1080px); padding-top: 1.5rem; }
            .layout { grid-template-columns: 1fr; }
            .booking-fields, .summary { grid-template-columns: 1fr; }
            .times { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 440px) {
            .times { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main>
    @if($holdSummary)
        <section class="card summary-card" aria-live="polite">
            @if($holdSummary['status'] === 'active')
                <span class="status">Time reserved</span>
                <h1>{{ $holdSummary['service_name'] }}</h1>
                <p class="muted">Your appointment time is temporarily reserved while you finish the booking.</p>
            @elseif($holdSummary['status'] === 'expired')
                <span class="status">Reservation expired</span>
                <h1>{{ $holdSummary['service_name'] }}</h1>
                <p class="muted">This reservation has expired. Choose another time to continue.</p>
            @elseif($holdSummary['status'] === 'converted' && $holdSummary['confirmation_pending'])
                <span class="status">Request received</span>
                <h1>{{ $holdSummary['service_name'] }}</h1>
                <p class="muted">This service requires confirmation. Your appointment is pending confirmation.</p>
            @elseif($holdSummary['status'] === 'converted')
                <span class="status">Appointment booked</span>
                <h1>{{ $holdSummary['service_name'] }}</h1>
                <p class="muted">Your booking is complete.</p>
            @else
                <span class="status">Reservation inactive</span>
                <h1>{{ $holdSummary['service_name'] }}</h1>
            @endif

            <dl class="summary">
                <div>
                    <dt>Service</dt>
                    <dd>{{ $holdSummary['service_name'] }}</dd>
                </div>
                @if($holdSummary['is_range'])
                    <div>
                        <dt>Stay</dt>
                        <dd>{{ $holdSummary['interval_label'] }}</dd>
                    </div>
                @else
                    <div>
                        <dt>Date</dt>
                        <dd>{{ $holdSummary['date_label'] }}</dd>
                    </div>
                    <div>
                        <dt>Time</dt>
                        <dd>{{ $holdSummary['time_label'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Timezone</dt>
                    <dd>{{ str_replace('_', ' ', $holdSummary['timezone']) }}</dd>
                </div>
                @if($holdSummary['location_address'])
                    <div>
                        <dt>{{ $holdSummary['location_label'] ?: 'Service address' }}</dt>
                        <dd>{{ $holdSummary['location_address'] }}</dd>
                    </div>
                @endif
            </dl>

            @error('booking')
                <p class="error">{{ $message }}</p>
            @enderror

            @if($holdSummary['status'] === 'active')
                <p
                    class="countdown"
                    data-countdown
                    data-expires-at="{{ $holdSummary['expires_at'] }}"
                    data-expired-message="This reservation has expired. Refresh to choose another time."
                >
                    Reserved for {{ max(1, (int) ceil($holdSummary['remaining_seconds'] / 60)) }} more minute(s).
                </p>

                <form
                    class="booking-form"
                    method="POST"
                    action="{{ route('scheduling.public.holds.complete', ['holdId' => $holdSummary['hold_id']], false) }}"
                >
                    @csrf
                    <div class="booking-fields">
                        <label class="booking-field" for="name">
                            Name
                            <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="255" required>
                            @error('name')<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <label class="booking-field" for="email">
                            Email
                            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required>
                            @error('email')<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <label class="booking-field" for="phone">
                            Phone <span class="muted">(optional)</span>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" maxlength="255">
                            @error('phone')<span class="error">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <div class="actions">
                        <button type="submit">Complete booking</button>
                    </div>
                </form>
            @else
                <a
                    class="button-link"
                    href="{{ route('scheduling.public.services.show', array_filter([
                        'serviceKey' => $holdSummary['service_key'],
                        'date' => $holdSummary['is_range'] ? null : $holdSummary['date'],
                    ]), false) }}"
                >Choose another time</a>
            @endif
        </section>
    @elseif($offerSummary)
        <section class="card summary-card" aria-live="polite">
            @if($offerSummary['status'] === 'active')
                <span class="status">Selection ready</span>
                <h1>{{ $offerSummary['service_name'] }}</h1>
                <p class="muted">This is a short-lived selection, not a capacity reservation yet. Availability is checked again before a real hold is created.</p>
            @else
                <span class="status">Selection expired</span>
                <h1>{{ $offerSummary['service_name'] }}</h1>
                <p class="muted">This selection has expired without reserving capacity.</p>
            @endif

            <dl class="summary">
                <div>
                    <dt>Service</dt>
                    <dd>{{ $offerSummary['service_name'] }}</dd>
                </div>
                @if($offerSummary['is_range'])
                    <div>
                        <dt>Stay</dt>
                        <dd>{{ $offerSummary['interval_label'] }}</dd>
                    </div>
                @else
                    <div>
                        <dt>Date</dt>
                        <dd>{{ $offerSummary['date_label'] }}</dd>
                    </div>
                    <div>
                        <dt>Time</dt>
                        <dd>{{ $offerSummary['time_label'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Timezone</dt>
                    <dd>{{ str_replace('_', ' ', $offerSummary['timezone']) }}</dd>
                </div>
                @if($offerSummary['location_address'])
                    <div>
                        <dt>Service address</dt>
                        <dd>{{ $offerSummary['location_address'] }}</dd>
                    </div>
                @endif
            </dl>

            @error('booking')
                <p class="error">{{ $message }}</p>
            @enderror

            @if($offerSummary['status'] === 'active')
                <p
                    class="countdown"
                    data-countdown
                    data-expires-at="{{ $offerSummary['expires_at'] }}"
                    data-expired-message="This selection has expired. Refresh to choose another time."
                >
                    Selection available for {{ max(1, (int) ceil($offerSummary['remaining_seconds'] / 60)) }} more minute(s).
                </p>

                <form
                    method="POST"
                    action="{{ route('scheduling.public.offers.hold', ['offerId' => $offerSummary['offer_id']], false) }}"
                >
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <button type="submit">Continue with this time</button>
                </form>
            @else
                <a
                    class="button-link"
                    href="{{ route('scheduling.public.services.show', array_filter([
                        'serviceKey' => $offerSummary['service_key'],
                        'date' => $offerSummary['is_range'] ? null : $offerSummary['date'],
                    ]), false) }}"
                >Choose another time</a>
            @endif
        </section>
    @else
        <h1>Schedule an appointment</h1>
        <p class="intro">Choose the appointment type first. We’ll ask only for details that affect that service before showing authoritative availability.</p>

        <div class="layout">
            <section class="card services" aria-labelledby="services-heading">
                <div class="service-link">
                    <span class="step">Step 1</span>
                    <strong id="services-heading">Choose appointment type</strong>
                    <span>Select the service that best matches what you need.</span>
                </div>

                @forelse($services as $service)
                    <a
                        class="service-link"
                        href="{{ route('scheduling.public.services.show', ['serviceKey' => $service->key], false) }}"
                        @if($selectedService?->is($service)) aria-current="page" @endif
                    >
                        <strong>{{ $service->name }}</strong>
                        <span>
                            @if($service->usesRangeDuration())
                                Flexible check-in/check-out
                            @else
                                {{ $service->duration_minutes }} minutes
                            @endif
                            @if($service->description)
                                · {{ $service->description }}
                            @endif
                        </span>
                    </a>
                @empty
                    <div class="service-link">
                        <strong>No public services are available.</strong>
                        <span>Please check back later.</span>
                    </div>
                @endforelse
            </section>

            <section class="card workspace" aria-live="polite">
                @if(!$selectedService)
                    <span class="step">Step 1</span>
                    <h2>Choose an appointment type</h2>
                    <p class="muted">Availability appears after you select a service.</p>
                @elseif($requiresCustomerSitePreparation)
                    <span class="step">Step 2</span>
                    <h2>Where should we meet?</h2>
                    <p class="muted">{{ $selectedService->name }} takes place at your location. We use the normalized service address before calculating travel-aware availability.</p>

                    <form
                        class="booking-form"
                        method="POST"
                        action="{{ route('scheduling.public.services.prepare', ['serviceKey' => $selectedService->key], false) }}"
                    >
                        @csrf
                        <div class="booking-fields">
                            <label class="booking-field" for="address_line_1">
                                Address line 1
                                <input id="address_line_1" name="address_line_1" type="text" value="{{ old('address_line_1') }}" autocomplete="address-line1" required>
                                @error('address_line_1')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <label class="booking-field" for="address_line_2">
                                Address line 2 <span class="muted">(optional)</span>
                                <input id="address_line_2" name="address_line_2" type="text" value="{{ old('address_line_2') }}" autocomplete="address-line2">
                                @error('address_line_2')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <label class="booking-field" for="city">
                                City
                                <input id="city" name="city" type="text" value="{{ old('city') }}" autocomplete="address-level2" required>
                                @error('city')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <label class="booking-field" for="region">
                                State / region
                                <input id="region" name="region" type="text" value="{{ old('region') }}" autocomplete="address-level1" required>
                                @error('region')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <label class="booking-field" for="postal_code">
                                Postal code
                                <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code') }}" autocomplete="postal-code" required>
                                @error('postal_code')<span class="error">{{ $message }}</span>@enderror
                            </label>
                            <label class="booking-field" for="country">
                                Country code
                                <input id="country" name="country" type="text" maxlength="2" value="{{ old('country', 'US') }}" autocomplete="country" required>
                                @error('country')<span class="error">{{ $message }}</span>@enderror
                            </label>
                        </div>
                        <div class="actions">
                            <button type="submit">Show available times</button>
                        </div>
                    </form>
                @else
                    <span class="step">{{ $preparedLocation ? 'Step 3' : 'Step 2' }}</span>
                    <h2>{{ $selectedService->name }}</h2>
                    <p class="muted">Times shown in {{ str_replace('_', ' ', $displayTimezone) }}.</p>

                    @if($preparedLocation && $preparedLocation['formatted_address'])
                        <div class="notice">
                            <strong>Service address</strong><br>
                            {{ $preparedLocation['formatted_address'] }}
                        </div>
                    @endif

                    @error('date')<p class="error">{{ $message }}</p>@enderror
                    @error('starts_at')<p class="error">{{ $message }}</p>@enderror
                    @error('range_starts_at')<p class="error">{{ $message }}</p>@enderror
                    @error('range_ends_at')<p class="error">{{ $message }}</p>@enderror
                    @error('address_line_1')<p class="error">{{ $message }}</p>@enderror

                    @if($selectedService->usesRangeDuration())
                        <form
                            class="booking-form"
                            method="POST"
                            action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}"
                        >
                            @csrf
                            <div class="booking-fields">
                                <label class="booking-field" for="range_starts_at">
                                    Check-in
                                    <input
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
                                <label class="booking-field" for="range_ends_at">
                                    Check-out
                                    <input
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
                            <p class="muted">Allowed duration: {{ $selectedService->minimumDurationMinutes() }}–{{ $selectedService->maximumDurationMinutes() }} minutes. The full interval is rechecked before a non-blocking selection is issued.</p>
                            <div class="actions">
                                <button type="submit">Check this stay</button>
                            </div>
                        </form>
                    @else
                        <form
                            class="date-form"
                            method="GET"
                            action="{{ route('scheduling.public.services.show', ['serviceKey' => $selectedService->key], false) }}"
                        >
                            <label for="date">
                                Appointment date
                                <input
                                    id="date"
                                    name="date"
                                    type="date"
                                    value="{{ old('date', $selectedDate?->format('Y-m-d')) }}"
                                    min="{{ now($displayTimezone)->format('Y-m-d') }}"
                                    max="{{ $maximumDate?->format('Y-m-d') }}"
                                    required
                                >
                            </label>
                            <div class="actions">
                                <button type="submit" class="button-secondary">View this date</button>
                            </div>
                        </form>

                        @if($availableTimes !== [])
                            <div class="times" aria-label="Available appointment times">
                                @foreach($availableTimes as $time)
                                    <form
                                        class="time-form"
                                        method="POST"
                                        action="{{ route('scheduling.public.services.offers.store', ['serviceKey' => $selectedService->key], false) }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="starts_at" value="{{ $time['starts_at'] }}">
                                        <button class="time" type="submit">{{ $time['label'] }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @else
                            <div class="empty">No appointment times are currently available for this date. Choose another date to continue.</div>
                        @endif
                    @endif

                    @if($preparedLocation)
                        <details>
                            <summary>Change service address</summary>
                            <form
                                class="booking-form"
                                method="POST"
                                action="{{ route('scheduling.public.services.prepare', ['serviceKey' => $selectedService->key], false) }}"
                                style="margin-top: 1rem;"
                            >
                                @csrf
                                <div class="booking-fields">
                                    <label class="booking-field">Address line 1<input name="address_line_1" type="text" value="{{ old('address_line_1', $preparedLocation['address_line_1']) }}" required></label>
                                    <label class="booking-field">Address line 2<input name="address_line_2" type="text" value="{{ old('address_line_2', $preparedLocation['address_line_2']) }}"></label>
                                    <label class="booking-field">City<input name="city" type="text" value="{{ old('city', $preparedLocation['city']) }}" required></label>
                                    <label class="booking-field">State / region<input name="region" type="text" value="{{ old('region', $preparedLocation['region']) }}" required></label>
                                    <label class="booking-field">Postal code<input name="postal_code" type="text" value="{{ old('postal_code', $preparedLocation['postal_code']) }}" required></label>
                                    <label class="booking-field">Country code<input name="country" type="text" maxlength="2" value="{{ old('country', $preparedLocation['country']) }}" required></label>
                                </div>
                                <button type="submit" class="button-secondary">Update address and recalculate</button>
                            </form>
                        </details>
                    @endif

                    <p class="footer-note">Selecting a time creates only a short-lived opaque offer. Capacity is not consumed until the next server-authoritative step succeeds.</p>
                @endif
            </section>
        </div>
    @endif
</main>

@if(($holdSummary && $holdSummary['status'] === 'active') || ($offerSummary && $offerSummary['status'] === 'active'))
    <script>
        (() => {
            const element = document.querySelector('[data-countdown]');

            if (! element) {
                return;
            }

            const expiresAt = Date.parse(element.dataset.expiresAt || '');

            if (! Number.isFinite(expiresAt)) {
                return;
            }

            const render = () => {
                const remainingSeconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));

                if (remainingSeconds === 0) {
                    element.textContent = element.dataset.expiredMessage || 'This selection has expired.';
                    return;
                }

                const minutes = Math.floor(remainingSeconds / 60);
                const seconds = remainingSeconds % 60;
                element.textContent = `${minutes}:${String(seconds).padStart(2, '0')} remaining.`;
                window.setTimeout(render, 1000);
            };

            render();
        })();
    </script>
@endif
</body>
</html>