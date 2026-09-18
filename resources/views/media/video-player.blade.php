<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }}</title>
    <style>
        html, body { margin: 0; min-height: 100%; background: #090e18; color: #fff; font-family: ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 1080px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { margin: 0 0 22px; font-size: clamp(1.4rem, 4vw, 2.25rem); line-height: 1.2; }
        video { display: block; width: 100%; max-height: 76vh; background: #000; border-radius: 16px; }
        p { color: #cbd5e1; line-height: 1.6; }
        a { color: #c4b5fd; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <video controls playsinline preload="metadata" @if($posterUrl) poster="{{ $posterUrl }}" @endif>
            <source src="{{ $videoUrl }}" type="{{ $mimeType }}">
            Your browser cannot play this video. <a href="{{ $videoUrl }}">Open the video file</a>.
        </video>
    </main>
</body>
</html>