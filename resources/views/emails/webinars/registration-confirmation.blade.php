<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>You’re registered</title>
</head>
<body>
    <p>Hey {{ $data->leadFirstName }},</p>

    <p>You’re confirmed for <strong>{{ $data->webinarTitle }}</strong>.</p>

    <p>
        <strong>When:</strong>
        {{ $data->formattedStart('F j, Y \a\t g:i A') }} {{ $data->webinarTimezone }}
    </p>

    <p>
        <strong>{{ ucwords($data->webinarPlatform) }} link:</strong>
        <a href="{{ $data->webinarJoinUrl }}">{{ $data->webinarJoinUrl }}</a>
    </p>

    <p>We’ll send reminders before the webinar starts.</p>

    @if (! empty($transactionalOptOutUrl))
        <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid #e2e8f0;">
            <p style="margin: 0; font-size: 12px; line-height: 20px; color: #64748b;">
                Don’t want webinar reminder or follow-up emails?
                <a
                    href="{{ $transactionalOptOutUrl }}"
                    style="color: #0f172a; text-decoration: underline;"
                >
                    Opt out of transactional webinar emails
                </a>.
            </p>
        </div>
    @endif

</body>
</html>