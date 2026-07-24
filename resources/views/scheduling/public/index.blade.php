<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $holdSummary ? 'noindex,nofollow' : 'index,follow' }}">
    <title>Schedule an appointment · {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172033;
            background: #f4f7f8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(13, 148, 136, .12), transparent 32rem),
                #f4f7f8;
        }

        a { color: inherit; }

        .shell {
            width: min(1080px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 3rem 0 5rem;
        }

        .eyebrow {
            margin: 0 0 .65rem;
            color: #0f766e;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        h1, h2, h3, p { margin-top: 0; }

        h1 {
            max-width: 720px;
            margin-bottom: .8rem;
            font-size: clamp(2rem, 6vw, 3.65rem);
            line-height: 1.02;
            letter-spacing: -.045em;
        }

        .intro {
            max-width: 680px;
            color: #556174;
            font-size: 1.05rem;
            line-height: 1.7;
        }

        .layout {
            display: grid;
            gap: 1.25rem;
            margin-top: 2.25rem;
        }

        .card {
            border: 1px solid #dce5e7;
            border-radius: 1.15rem;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 18px 45px rgba(23, 32, 51, .07);
        }

        .services { overflow: hidden; }

        .service-link {
            display: block;
            padding: 1.2rem 1.25rem;
            border-bottom: 1px solid #e6edef;
            text-decoration: none;
            transition: background .15s ease, transform .15s ease;
        }

        .service-link:last-child { border-bottom: 0; }
        .service-link:hover { background: #f2fbfa; }
        .service-link[aria-current="page"] { background: #e7f7f5; }

        .service-link strong {
            display: block;
            margin-bottom: .35rem;
            font-size: 1rem;
        }

        .service-link span,
        .muted {
            color: #647184;
            font-size: .9rem;
            line-height: 1.55;
        }

        .availability { padding: 1.35rem; }

        .availability-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .availability h2 {
            margin-bottom: .35rem;
            font-size: 1.35rem;
            letter-spacing: -.02em;
        }

        .date-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: .65rem;
        }

        .date-form label {
            display: grid;
            gap: .35rem;
            color: #455166;
            font-size: .78rem;
            font-weight: 750;
        }

        input[type="date"] {
            min-height: 2.7rem;
            border: 1px solid #bdcbd0;
            border-radius: .7rem;
            padding: .6rem .75rem;
            background: #fff;
            color: #172033;
            font: inherit;
        }

        button,
        .button-link {
            min-height: 2.7rem;
            border: 0;
            border-radius: .7rem;
            padding: .65rem 1rem;
            background: #0f766e;
            color: #fff;
            font: inherit;
            font-weight: 750;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover,
        .button-link:hover { background: #115e59; }

        .error {
            margin: 0 0 1rem;
            border: 1px solid #fecaca;
            border-radius: .8rem;
            padding: .8rem 1rem;
            background: #fff1f2;
            color: #9f1239;
            font-size: .9rem;
        }

        .times {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: .7rem;
        }

        .time-form { margin: 0; }

        .time {
            width: 100%;
            border: 1px solid #b7d9d5;
            border-radius: .8rem;
            padding: .85rem .95rem;
            background: #f3fbfa;
            color: #134e4a;
            font-weight: 750;
            text-align: center;
        }

        .time:hover {
            border-color: #5eaaa2;
            background: #e4f6f3;
        }

        .empty {
            border: 1px dashed #c9d4d8;
            border-radius: .9rem;
            padding: 1.2rem;
            color: #647184;
            background: #fafcfc;
            line-height: 1.6;
        }

        .footer-note {
            margin-top: 1rem;
            color: #738093;
            font-size: .82rem;
        }

        .hold-card {
            max-width: 720px;
            margin-top: 2.25rem;
            padding: clamp(1.35rem, 5vw, 2.2rem);
        }

        .hold-status {
            display: inline-flex;
            margin-bottom: 1rem;
            border-radius: 999px;
            padding: .4rem .7rem;
            background: #e7f7f5;
            color: #115e59;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .hold-details {
            display: grid;
            gap: .8rem;
            margin: 1.4rem 0;
            border-top: 1px solid #e6edef;
            border-bottom: 1px solid #e6edef;
            padding: 1.2rem 0;
        }

        .hold-details div {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: .5rem 1rem;
        }

        .hold-details dt {
            color: #647184;
            font-size: .85rem;
        }

        .hold-details dd {
            margin: 0;
            font-weight: 750;
            text-align: right;
        }

        .countdown {
            margin-bottom: 1.2rem;
            color: #0f766e;
            font-weight: 800;
        }

        @media (min-width: 840px) {
            .layout {
                grid-template-columns: minmax(250px, .78fr) minmax(0, 1.55fr);
                align-items: start;
            }
        }
    </style>
</head>
<body>
<main class="shell">
    <p class="eyebrow">{{ config('app.name') }}</p>

    @if($holdSummary)
        <h1>Review your reserved time</h1>
        <p class="intro">
            This time is held temporarily while the booking is completed.
        </p>

        <section class="card hold-card" aria-live="polite">
            @if($holdSummary['status'] === 'active')
                <span class="hold-status">Time reserved</span>
                <h2>{{ $holdSummary['service_name'] }}</h2>
                <p class="muted">
                    Your selected appointment time is currently reserved.
                </p>
            @elseif($holdSummary['status'] === 'expired')
                <span class="hold-status">Reservation expired</span>
                <h2>This reservation has expired.</h2>
                <p class="muted">
                    Return to the service availability page to choose another time.
                </p>
            @elseif($holdSummary['status'] === 'converted')
                <span class="hold-status">Booking completed</span>
                <h2>This reservation has already been completed.</h2>
            @else
                <span class="hold-status">Reservation inactive</span>
                <h2>This reservation is no longer active.</h2>
            @endif

            <dl class="hold-details">
                <div>
                    <dt>Date</dt>
                    <dd>{{ $holdSummary['date_label'] }}</dd>
                </div>
                <div>
                    <dt>Time</dt>
                    <dd>{{ $holdSummary['time_label'] }}</dd>
                </div>
                <div>
                    <dt>Timezone</dt>
                    <dd>{{ str_replace('_', ' ', $holdSummary['timezone']) }}</dd>
                </div>
            </dl>

            @if($holdSummary['status'] === 'active')
                <p
                    class="countdown"
                    data-hold-countdown
                    data-expires-at="{{ $holdSummary['expires_at'] }}"
                    data-remaining-seconds="{{ $holdSummary['remaining_seconds'] }}"
                >
                    Reserved for {{ max(1, (int) ceil($holdSummary['remaining_seconds'] / 60)) }} more minute(s).
                </p>
            @else
                <p
                    class="countdown"
                    data-remaining-seconds="0"
                >
                    No reservation time remains.
                </p>
            @endif

            <a
                class="button-link"
                href="{{ route('scheduling.public.services.show', [
                    'serviceKey' => $holdSummary['service_key'],
                    'date' => $holdSummary['date'],
                ], false) }}"
            >
                View available times
            </a>
        </section>
    @else
        <h1>Schedule an appointment</h1>
        <p class="intro">
            Choose a service, review current availability, and find a time that works.
        </p>

        <div class="layout">
            <section class="card services" aria-labelledby="services-heading">
                <div class="service-link">
                    <strong id="services-heading">Available services</strong>
                    <span>Select a service to view appointment times.</span>
                </div>

                @forelse($services as $service)
                    <a
                        class="service-link"
                        href="{{ route('scheduling.public.services.show', ['serviceKey' => $service->key], false) }}"
                        @if($selectedService?->is($service)) aria-current="page" @endif
                    >
                        <strong>{{ $service->name }}</strong>
                        <span>
                            {{ $service->duration_minutes }} minutes
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

            <section class="card availability" aria-live="polite">
                @if($selectedService)
                    <div class="availability-head">
                        <div>
                            <h2>{{ $selectedService->name }}</h2>
                            <p class="muted">
                                Times shown in {{ str_replace('_', ' ', $displayTimezone) }}.
                            </p>
                        </div>

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
                            <button type="submit">View times</button>
                        </form>
                    </div>

                    @error('date')
                        <p class="error">{{ $message }}</p>
                    @enderror

                    @error('starts_at')
                        <p class="error">{{ $message }}</p>
                    @enderror

                    @error('idempotency_key')
                        <p class="error">{{ $message }}</p>
                    @enderror

                    @if($availableTimes !== [])
                        <div class="times" aria-label="Available appointment times">
                            @foreach($availableTimes as $time)
                                <form
                                    class="time-form"
                                    method="POST"
                                    action="{{ route('scheduling.public.services.reserve', ['serviceKey' => $selectedService->key], false) }}"
                                >
                                    @csrf
                                    <input
                                        type="hidden"
                                        name="starts_at"
                                        value="{{ $time['starts_at'] }}"
                                    >
                                    <input
                                        type="hidden"
                                        name="idempotency_key"
                                        value="{{ $time['idempotency_key'] }}"
                                    >
                                    <button class="time" type="submit">
                                        {{ $time['label'] }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @else
                        <div class="empty">
                            No appointment times are currently available for this date. Choose another date to continue.
                        </div>
                    @endif

                    <p class="footer-note">
                        Availability is recalculated when a time is selected and may change before a reservation is created.
                    </p>
                @else
                    <h2>Choose a service</h2>
                    <p class="muted">
                        Select one of the public services to view its current appointment availability.
                    </p>
                @endif
            </section>
        </div>
    @endif
</main>

@if($holdSummary && $holdSummary['status'] === 'active')
    <script>
        (() => {
            const element = document.querySelector('[data-hold-countdown]');

            if (! element) {
                return;
            }

            const expiresAt = Date.parse(element.dataset.expiresAt || '');

            if (! Number.isFinite(expiresAt)) {
                return;
            }

            const render = () => {
                const remainingSeconds = Math.max(
                    0,
                    Math.ceil((expiresAt - Date.now()) / 1000),
                );

                element.dataset.remainingSeconds = String(remainingSeconds);

                if (remainingSeconds === 0) {
                    element.textContent = 'This reservation has expired. Refresh to choose another time.';

                    return;
                }

                const minutes = Math.floor(remainingSeconds / 60);
                const seconds = remainingSeconds % 60;

                element.textContent = `Reserved for ${minutes}:${String(seconds).padStart(2, '0')} more.`;
                window.setTimeout(render, 1000);
            };

            render();
        })();
    </script>
@endif
</body>
</html>