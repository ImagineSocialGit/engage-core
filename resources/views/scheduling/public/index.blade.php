<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
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

        .services {
            overflow: hidden;
        }

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

        .availability {
            padding: 1.35rem;
        }

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

        button {
            min-height: 2.7rem;
            border: 0;
            border-radius: .7rem;
            padding: .65rem 1rem;
            background: #0f766e;
            color: #fff;
            font: inherit;
            font-weight: 750;
            cursor: pointer;
        }

        button:hover { background: #115e59; }

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

        .time {
            border: 1px solid #b7d9d5;
            border-radius: .8rem;
            padding: .85rem .95rem;
            background: #f3fbfa;
            color: #134e4a;
            font-weight: 750;
            text-align: center;
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

                @if($availableTimes !== [])
                    <div class="times" aria-label="Available appointment times">
                        @foreach($availableTimes as $time)
                            <div
                                class="time"
                                data-starts-at="{{ $time['starts_at'] }}"
                                data-ends-at="{{ $time['ends_at'] }}"
                            >
                                {{ $time['label'] }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty">
                        No appointment times are currently available for this date. Choose another date to continue.
                    </div>
                @endif

                <p class="footer-note">
                    Availability is calculated when this page loads and may change before booking is completed.
                </p>
            @else
                <h2>Choose a service</h2>
                <p class="muted">
                    Select one of the public services to view its current appointment availability.
                </p>
            @endif
        </section>
    </div>
</main>
</body>
</html>