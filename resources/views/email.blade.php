<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background:#f8fafc; color:#0f172a; font-family:Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; margin:0; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px; border-bottom:1px solid #e2e8f0;">
                            <div style="font-size:18px; font-weight:700; color:#0f172a;">
                                {{ config('brand.name', config('app.name')) }}
                            </div>

                            @if(! empty($preheader))
                                <div style="margin-top:8px; font-size:14px; line-height:22px; color:#64748b;">
                                    {{ $preheader }}
                                </div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            @if(! empty($headline))
                                <h1 style="margin:0 0 20px; font-size:24px; line-height:32px; color:#0f172a;">
                                    {{ $headline }}
                                </h1>
                            @endif

                            @php
                                $paragraphs = $body ?? $message ?? [];

                                if (is_string($paragraphs)) {
                                    $paragraphs = preg_split("/\r\n|\n|\r/", $paragraphs) ?: [$paragraphs];
                                }

                                $paragraphs = array_values(array_filter($paragraphs, fn ($paragraph) => filled($paragraph)));

                                $hasCta = ! empty($cta)
                                    && is_array($cta)
                                    && filled($cta['label'] ?? null)
                                    && filled($cta['url'] ?? null);

                                $resolvedCtas = [];

                                if (! empty($ctas) && is_array($ctas)) {
                                    foreach ($ctas as $item) {
                                        if (
                                            is_array($item)
                                            && filled($item['label'] ?? null)
                                            && filled($item['url'] ?? null)
                                        ) {
                                            $resolvedCtas[] = $item;
                                        }
                                    }
                                }

                                if ($resolvedCtas === [] && $hasCta) {
                                    $resolvedCtas[] = $cta;
                                }

                                $hasInlineCta = false;
                            @endphp

                            @foreach($paragraphs as $paragraph)
                                @if(trim($paragraph) === '{cta}')
                                    @php $hasInlineCta = true; @endphp

                                    @if($resolvedCtas !== [])
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
                                            @foreach($resolvedCtas as $resolvedCta)
                                                <tr>
                                                    <td style="{{ ! $loop->first ? 'padding-top:12px;' : '' }}">
                                                        <a href="{{ $resolvedCta['url'] }}" style="display:inline-block; background:#0f172a; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700; padding:14px 22px; border-radius:999px;">
                                                            {{ $resolvedCta['label'] }}
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif
                                @else
                                    <p style="margin:0 0 18px; font-size:16px; line-height:26px; color:#334155;">
                                        {!! nl2br(e($paragraph)) !!}
                                    </p>
                                @endif
                            @endforeach

                            @if(! empty($details) && is_array($details))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                                    @foreach($details as $label => $value)
                                        @continue(blank($value))
                                        <tr>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e2e8f0; font-size:14px; color:#64748b; width:35%;">
                                                {{ $label }}
                                            </td>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e2e8f0; font-size:14px; color:#0f172a; font-weight:600;">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if($resolvedCtas !== [] && ! $hasInlineCta)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
                                    @foreach($resolvedCtas as $resolvedCta)
                                        <tr>
                                            <td style="{{ ! $loop->first ? 'padding-top:12px;' : '' }}">
                                                <a href="{{ $resolvedCta['url'] }}" style="display:inline-block; background:#0f172a; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700; padding:14px 22px; border-radius:999px;">
                                                    {{ $resolvedCta['label'] }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if(! empty($secondary_link) && is_array($secondary_link) && ! empty($secondary_link['label']) && ! empty($secondary_link['url']))
                                <p style="margin:18px 0 0; font-size:14px; line-height:22px; color:#64748b;">
                                    {{ $secondary_link['label'] }}:
                                    <a href="{{ $secondary_link['url'] }}" style="color:#0f172a; text-decoration:underline;">
                                        {{ $secondary_link['url'] }}
                                    </a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 32px; border-top:1px solid #e2e8f0; background:#f8fafc;">
                            @if(! empty($footer))
                                <p style="margin:0 0 12px; font-size:12px; line-height:20px; color:#64748b;">
                                    {{ $footer }}
                                </p>
                            @endif

                            @if(! empty($transactionalOptOutUrl))
                                <p style="margin:0; font-size:12px; line-height:20px; color:#64748b;">
                                    Don’t want these emails?
                                    <a href="{{ $transactionalOptOutUrl }}" style="color:#0f172a; text-decoration:underline;">
                                        Opt out here
                                    </a>.
                                </p>
                            @endif

                            @if(! empty($unsubscribeUrl))
                                <p style="margin:{{ ! empty($transactionalOptOutUrl) ? '8px 0 0' : '0' }}; font-size:12px; line-height:20px; color:#64748b;">
                                    You can
                                    <a href="{{ $unsubscribeUrl }}" style="color:#0f172a; text-decoration:underline;">
                                        unsubscribe from marketing emails here
                                    </a>.
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>