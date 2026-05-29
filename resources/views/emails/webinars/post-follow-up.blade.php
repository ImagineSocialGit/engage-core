<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body>
    <p>Hey {{ $data->leadFirstName }},</p>

    @if($followUpType === 'missed')
        <p>Sorry we missed you for <strong>{{ $data->webinarTitle }}</strong>.</p>
        <p>We’ll be following up with helpful next steps.</p>
    @elseif($followUpType === 'replay')
        <p>Thanks for attending <strong>{{ $data->webinarTitle }}</strong>.</p>
        <p>We’ll be following up with your replay and next steps.</p>
    @endif

    <p>
        <strong>Original webinar time:</strong>
        {{ $data->formattedStart('F j, Y \a\t g:i A') }} {{ $data->webinarTimezone }}
    </p>

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