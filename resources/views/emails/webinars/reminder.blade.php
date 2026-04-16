<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body>
    <p>Hey {{ $data->leadFirstName }},</p>

    @switch($messageType)
        @case('reminder_10d')
            <p>Your webinar <strong>{{ $data->webinarTitle }}</strong> is coming up in 10 days.</p>
            @break

        @case('reminder_7d')
            <p>Your webinar <strong>{{ $data->webinarTitle }}</strong> is one week away.</p>
            @break

        @case('reminder_24h')
            <p>This is your reminder that <strong>{{ $data->webinarTitle }}</strong> is tomorrow.</p>
            @break

        @case('reminder_30m')
            <p><strong>{{ $data->webinarTitle }}</strong> starts in 30 minutes.</p>
            @break

        @case('reminder_10m')
            <p><strong>{{ $data->webinarTitle }}</strong> starts in 10 minutes.</p>
            @break

        @case('late_joiner_5m')
            <p><strong>{{ $data->webinarTitle }}</strong> is live now.</p>
            @break
    @endswitch

    <p>
        <strong>When:</strong>
        {{ $data->formattedStart('F j, Y \a\t g:i A') }} {{ $data->webinarTimezone }}
    </p>

    <p>
        <strong>Join link:</strong>
        <a href="{{ $data->joinUrl }}">{{ $data->joinUrl }}</a>
    </p>
</body>
</html>