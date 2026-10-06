<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? '' }}</title>
</head>
<body style="margin:0; padding:24px; background:#ffffff; color:#111827; font-family:Arial, Helvetica, sans-serif;">
    @if(! empty($preheader))
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
            {{ $preheader }}
        </div>
    @endif

    <div style="max-width:680px;">
        @foreach($body ?? [] as $paragraph)
            @if(trim((string) $paragraph) === '{cta}')
                @if(! empty($cta) && is_array($cta) && filled($cta['label'] ?? null) && filled($cta['url'] ?? null))
                    <p style="margin:0 0 18px; font-size:16px; line-height:24px;">
                        <a href="{{ $cta['url'] }}" style="color:#111827; text-decoration:underline;">
                            {{ $cta['label'] }}
                        </a>
                    </p>
                @endif

                @foreach($ctas ?? [] as $item)
                    @if(is_array($item) && filled($item['label'] ?? null) && filled($item['url'] ?? null))
                        <p style="margin:0 0 18px; font-size:16px; line-height:24px;">
                            <a href="{{ $item['url'] }}" style="color:#111827; text-decoration:underline;">
                                {{ $item['label'] }}
                            </a>
                        </p>
                    @endif
                @endforeach
            @elseif(trim((string) $paragraph) === '{media}')
                {media}
            @elseif(filled($paragraph))
                <p style="margin:0 0 18px; font-size:16px; line-height:24px; color:#111827;">
                    {!! nl2br(e($paragraph)) !!}
                </p>
            @endif
        @endforeach

        @if(! in_array('{cta}', $body ?? [], true))
            @if(! empty($cta) && is_array($cta) && filled($cta['label'] ?? null) && filled($cta['url'] ?? null))
                <p style="margin:0 0 18px; font-size:16px; line-height:24px;">
                    <a href="{{ $cta['url'] }}" style="color:#111827; text-decoration:underline;">
                        {{ $cta['label'] }}
                    </a>
                </p>
            @endif

            @foreach($ctas ?? [] as $item)
                @if(is_array($item) && filled($item['label'] ?? null) && filled($item['url'] ?? null))
                    <p style="margin:0 0 18px; font-size:16px; line-height:24px;">
                        <a href="{{ $item['url'] }}" style="color:#111827; text-decoration:underline;">
                            {{ $item['label'] }}
                        </a>
                    </p>
                @endif
            @endforeach
        @endif

        @if(! empty($details) && is_array($details))
            <div style="margin:24px 0;">
                @foreach($details as $label => $value)
                    @continue(blank($value))
                    <p style="margin:0 0 8px; font-size:15px; line-height:22px; color:#111827;">
                        <strong>{{ $label }}:</strong> {{ $value }}
                    </p>
                @endforeach
            </div>
        @endif

        @if(! empty($secondary_link) && is_array($secondary_link) && filled($secondary_link['label'] ?? null) && filled($secondary_link['url'] ?? null))
            <p style="margin:18px 0; font-size:14px; line-height:22px; color:#4b5563;">
                {{ $secondary_link['label'] }}:
                <a href="{{ $secondary_link['url'] }}" style="color:#111827; text-decoration:underline;">
                    {{ $secondary_link['url'] }}
                </a>
            </p>
        @endif

        @if(! empty($campaignContact) && is_array($campaignContact))
            <div style="margin:24px 0 0; font-size:14px; line-height:22px; color:#4b5563;">
                @if(filled($campaignContact['phone'] ?? null))
                    <div>
                        @if(filled($campaignContact['phone_url'] ?? null))
                            <a href="{{ $campaignContact['phone_url'] }}" style="color:#111827; text-decoration:underline;">
                                {{ $campaignContact['phone'] }}
                            </a>
                        @else
                            {{ $campaignContact['phone'] }}
                        @endif
                    </div>
                @endif

                @if(filled($campaignContact['email'] ?? null))
                    <div>
                        @if(filled($campaignContact['email_url'] ?? null))
                            <a href="{{ $campaignContact['email_url'] }}" style="color:#111827; text-decoration:underline;">
                                {{ $campaignContact['email'] }}
                            </a>
                        @else
                            {{ $campaignContact['email'] }}
                        @endif
                    </div>
                @endif

                @if(filled($campaignContact['schedule_url'] ?? null))
                    <div>
                        <a href="{{ $campaignContact['schedule_url'] }}" style="color:#111827; text-decoration:underline;">
                            {{ $campaignContact['schedule_label'] ?? $campaignContact['schedule_url'] }}
                        </a>
                    </div>
                @endif
            </div>
        @endif

        @if(! empty($footer))
            <p style="margin:24px 0 0; font-size:12px; line-height:18px; color:#6b7280;">
                {{ $footer }}
            </p>
        @endif

        @if(! empty($transactionalOptOutUrl))
            <p style="margin:12px 0 0; font-size:12px; line-height:18px; color:#6b7280;">
                <a href="{{ $transactionalOptOutUrl }}" style="color:#4b5563; text-decoration:underline;">
                    Manage email preferences
                </a>
            </p>
        @endif

        @if(! empty($unsubscribeUrl))
            <p style="margin:12px 0 0; font-size:12px; line-height:18px; color:#6b7280;">
                <a href="{{ $unsubscribeUrl }}" style="color:#4b5563; text-decoration:underline;">
                    Unsubscribe
                </a>
            </p>
        @endif
    </div>
</body>
</html>