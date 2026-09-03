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
    $posterUrl = is_string($media['poster_url'] ?? null) ? trim($media['poster_url']) : '';
    $actionLabel = match ($kind) {
        'video' => '▶ Watch video',
        'audio' => '▶ Listen',
        'image' => 'View image',
        'document' => 'Open document',
        default => 'Open file',
    };
@endphp

@if($url !== '')
    <a href="{{ $url }}" style="display:block; margin:6px 0; color:#0f172a; text-decoration:none;">
        @if($kind === 'image')
            <img src="{{ $sourceUrl }}" alt="{{ $title }}" width="576" style="display:block; width:100%; max-width:576px; height:auto; border:0; border-radius:14px;">
        @elseif($kind === 'video' && $posterUrl !== '')
            <img src="{{ $posterUrl }}" alt="{{ $title }} — {{ $actionLabel }}" width="576" style="display:block; width:100%; max-width:576px; height:auto; border:0; border-radius:14px 14px 0 0;">
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