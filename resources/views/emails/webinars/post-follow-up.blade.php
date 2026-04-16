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
</body>
</html>