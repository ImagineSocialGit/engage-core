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
        <strong>{{ ucwords($data->platform) }} link:</strong>
        <a href="{{ $data->joinUrl }}">{{ $data->joinUrl }}</a>
    </p>

    <p>We’ll send reminders before the webinar starts.</p>
</body>
</html>