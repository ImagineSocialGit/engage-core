@php
    $media = is_array($media ?? null) ? $media : [];
    $kind = is_string($media['kind'] ?? null) ? $media['kind'] : 'file';
    $title = is_string($media['title'] ?? null) && trim($media['title']) !== ''
        ? trim($media['title'])
        : 'Open media';
    $url = is_string($media['url'] ?? null) ? trim($media['url']) : '';
    $sourceUrl = is_string($sourceUrl ?? null) && trim($sourceUrl) !== ''
        ? trim($sourceUrl)
        : $url;
    $videoSourceUrl = is_string($videoSourceUrl ?? null) ? trim($videoSourceUrl) : '';
    $posterUrl = is_string($media['poster_url'] ?? null) ? trim($media['poster_url']) : '';
    $mimeType = is_string($media['mime_type'] ?? null) && trim($media['mime_type']) !== ''
        ? trim($media['mime_type'])
        : 'video/mp4';
    $actionLabel = match ($kind) {
        'video' => '▶ Watch video',
        'audio' => '▶ Listen',
        'image' => 'View image',
        'document' => 'Open document',
        default => 'Open file',
    };
@endphp

@if($url !== '')
    @if($kind === 'video' && $videoSourceUrl !== '')
        <!--[if !mso]><!-- -->
        <video class="engage-email-video" controls playsinline preload="none" width="{{ $displayWidth }}" @if($posterUrl !== '') poster="{{ $posterUrl }}" @endif style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; margin:6px 0; background:#000; border-radius:14px;">
            <source src="{{ $videoSourceUrl }}" type="{{ $mimeType }}">
            <a href="{{ $url }}" style="display:block; width:{{ $displayWidth }}px; max-width:100%; margin:6px 0; color:#0f172a; text-decoration:none;">
                @if($posterUrl !== '')
                    <img src="{{ $posterUrl }}" alt="{{ $title }} — {{ $actionLabel }}" width="{{ $displayWidth }}" style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; border:0; border-radius:14px 14px 0 0;">
                    <span style="display:block; padding:13px 16px; border:1px solid #e2e8f0; border-top:0; border-radius:0 0 14px 14px; background:#f8fafc; font-size:14px; line-height:20px; font-weight:700; color:#0f172a;">
                        {{ $actionLabel }} · {{ $title }}
                    </span>
                @else
                    <span style="display:block; padding:20px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; text-align:center;">
                        <span style="display:block; font-size:34px; line-height:42px;">▶</span>
                        <span style="display:block; margin-top:4px; font-size:15px; line-height:22px; font-weight:700; color:#0f172a;">{{ $title }}</span>
                        <span style="display:block; margin-top:4px; font-size:13px; line-height:20px; color:#475569;">{{ $actionLabel }}</span>
                    </span>
                @endif
            </a>
        </video>
        <!--<![endif]-->

        <!--[if mso]>
        <a href="{{ $url }}" style="display:block; width:{{ $displayWidth }}px; max-width:100%; margin:6px 0; color:#0f172a; text-decoration:none;">
            @if($posterUrl !== '')
                <img src="{{ $posterUrl }}" alt="{{ $title }} — {{ $actionLabel }}" width="{{ $displayWidth }}" style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; border:0;">
            @else
                <span style="display:block; padding:20px; border:1px solid #e2e8f0; background:#f8fafc; text-align:center; font-size:15px; line-height:22px; font-weight:700; color:#0f172a;">{{ $actionLabel }} · {{ $title }}</span>
            @endif
        </a>
        <![endif]-->

        <a class="engage-email-video-fallback" href="{{ $url }}" style="display:none; mso-hide:all; width:{{ $displayWidth }}px; max-width:100%; margin:6px 0; color:#0f172a; text-decoration:none;">
            @if($posterUrl !== '')
                <img src="{{ $posterUrl }}" alt="{{ $title }} — {{ $actionLabel }}" width="{{ $displayWidth }}" style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; border:0; border-radius:14px 14px 0 0;">
                <span style="display:block; padding:13px 16px; border:1px solid #e2e8f0; border-top:0; border-radius:0 0 14px 14px; background:#f8fafc; font-size:14px; line-height:20px; font-weight:700; color:#0f172a;">
                    {{ $actionLabel }} · {{ $title }}
                </span>
            @else
                <span style="display:block; padding:20px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; text-align:center;">
                    <span style="display:block; font-size:34px; line-height:42px;">▶</span>
                    <span style="display:block; margin-top:4px; font-size:15px; line-height:22px; font-weight:700; color:#0f172a;">{{ $title }}</span>
                    <span style="display:block; margin-top:4px; font-size:13px; line-height:20px; color:#475569;">{{ $actionLabel }}</span>
                </span>
            @endif
        </a>
    @else
        <a href="{{ $url }}" style="display:block; width:{{ $displayWidth }}px; max-width:100%; margin:6px 0; color:#0f172a; text-decoration:none;">
            @if($kind === 'image')
                <img src="{{ $sourceUrl }}" alt="{{ $title }}" width="{{ $displayWidth }}" style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; border:0; border-radius:14px;">
            @elseif($kind === 'video' && $posterUrl !== '')
                <img src="{{ $posterUrl }}" alt="{{ $title }} — {{ $actionLabel }}" width="{{ $displayWidth }}" style="display:block; width:100%; max-width:{{ $displayWidth }}px; height:auto; border:0; border-radius:14px 14px 0 0;">
                <span style="display:block; padding:13px 16px; border:1px solid #e2e8f0; border-top:0; border-radius:0 0 14px 14px; background:#f8fafc; font-size:14px; line-height:20px; font-weight:700; color:#0f172a;">
                    {{ $actionLabel }} · {{ $title }}
                </span>
            @else
                <span style="display:block; padding:20px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; text-align:center;">
                    @if($kind === 'video')
                        <span style="display:block; font-size:34px; line-height:42px;">▶</span>
                    @endif
                    <span style="display:block; margin-top:4px; font-size:15px; line-height:22px; font-weight:700; color:#0f172a;">{{ $title }}</span>
                    <span style="display:block; margin-top:4px; font-size:13px; line-height:20px; color:#475569;">{{ $actionLabel }}</span>
                </span>
            @endif
        </a>
    @endif
@endif