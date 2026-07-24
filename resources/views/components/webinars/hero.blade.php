@props([
    'page',
    'style',
    'tokens',
    'series',
    'eventDetailsItems',
    'countdownTarget',
    'heroCountdown',
])

@php
    $hero = is_array($page['hero'] ?? null)
        ? $page['hero']
        : [];
    $heroStyle = is_array($style['hero'] ?? null)
        ? $style['hero']
        : [];

    $urgencyStats = is_array($page['urgency_stats'] ?? null)
        ? $page['urgency_stats']
        : [];
    $urgencyStatsStyle = is_array($style['urgency_stats'] ?? null)
        ? $style['urgency_stats']
        : [];

    $webinarTitle = is_array($page['webinar_title'] ?? null)
        ? $page['webinar_title']
        : [];
    $webinarTitleStyle = is_array($style['webinar_title'] ?? null)
        ? $style['webinar_title']
        : [];

    $primaryCta = is_array($page['primary_cta'] ?? null)
        ? $page['primary_cta']
        : [];
    $primaryCtaStyle = is_array($style['primary_cta'] ?? null)
        ? $style['primary_cta']
        : [];

    $countdown = is_array($page['countdown'] ?? null)
        ? $page['countdown']
        : [];

    $heroBody = $hero['body'] ?? null;
    $heroSupportingCopy = $hero['supporting_copy'] ?? null;
    $heroClosingCopy = $hero['closing_copy'] ?? null;

    $heroClosingCopyHighlight = is_string($hero['closing_copy_highlight'] ?? null)
        ? $hero['closing_copy_highlight']
        : null;

    $heroClosingCopyHighlightPosition = is_string($heroClosingCopy)
        && filled($heroClosingCopy)
        && filled($heroClosingCopyHighlight)
            ? mb_strpos($heroClosingCopy, $heroClosingCopyHighlight)
            : false;

    $heroBullets = is_array($hero['bullets'] ?? null)
        ? $hero['bullets']
        : [];
    $heroBulletItems = is_array($heroBullets['list'] ?? null)
        ? $heroBullets['list']
        : [];

    $urgencyStatItems = is_array($urgencyStats['items'] ?? null)
        ? $urgencyStats['items']
        : [];
@endphp

@if($hero['enabled'] ?? true)
<div
    x-ref="heroSection"
    class="{{ $heroStyle['section'] ?? 'bg-secondary text-white' }}"
>
    <div class="{{ $heroStyle['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-14 sm:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center' }}">
        <div class="{{ $heroStyle['wrapper'] ?? 'max-w-4xl text-left' }} {{ $heroStyle['align'] ?? 'text-left' }}">
            @if(filled($hero['eyebrow'] ?? null))
                <p class="{{ $heroStyle['eyebrow'] ?? ($tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]') }}">
                    {{ $hero['eyebrow'] }}
                </p>
            @endif

            <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} {{ $heroStyle['title'] ?? 'mt-4' }}">
                <span class="block">
                    {{ $hero['title'] ?? $hero['title_prefix'] ?? 'Register for Webinar' }}
                </span>

                @if(filled($hero['subtitle'] ?? null))
                    <span class="{{ $heroStyle['subtitle'] ?? 'mt-2 block text-[0.92em] text-[#6f9fd9]' }}">
                        {{ $hero['subtitle'] }}
                    </span>
                @elseif(blank($hero['title'] ?? null))
                    <span class="block">
                        {{ $series->title }}
                    </span>
                @endif
            </h2>

            @if(filled($heroBody))
                @if(is_array($heroBody))
                    @foreach($heroBody as $paragraph)
                        @if(is_string($paragraph))
                            <div class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} {{ $heroStyle['body'] ?? 'mt-6' }}">
                                {{ $paragraph }}
                            </div>
                        @elseif(is_array($paragraph))
                            <div class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} {{ $heroStyle['body'] ?? 'mt-6' }}">
                                <p class="space-x-1">
                                    @foreach($paragraph as $part)
                                        @if(is_string($part))
                                            <span>{{ $part }}</span>
                                        @elseif(is_array($part) && is_string($part['text'] ?? null))
                                            @if($part['emphasis'] ?? false)
                                                <strong class="{{ $heroStyle['body_emphasis'] ?? 'text-primary' }}">
                                                    {{ $part['text'] }}
                                                </strong>
                                            @else
                                                <span>{{ $part['text'] }}</span>
                                            @endif
                                        @endif
                                    @endforeach
                                </p>
                            </div>
                        @endif
                    @endforeach
                @elseif(is_string($heroBody))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} {{ $heroStyle['body'] ?? 'mt-6' }}">
                        {{ $heroBody }}
                    </p>
                @endif
            @endif

            @if(filled($heroSupportingCopy))
                <div class="{{ $heroStyle['supporting_copy_wrapper'] ?? 'mt-4 space-y-2' }}">
                    @if(is_array($heroSupportingCopy))
                        @foreach($heroSupportingCopy as $paragraph)
                            @if(is_string($paragraph))
                                <p class="{{ $heroStyle['supporting_copy'] ?? '' }}{{ $loop->last ? ' text-lg font-semibold' : ' text-base' }}">
                                    {{ $paragraph }}
                                </p>
                            @elseif(is_array($paragraph))
                                <p class="{{ $heroStyle['supporting_copy'] ?? '' }}{{ $loop->last ? ' text-lg font-semibold' : ' text-base' }}">
                                    @foreach($paragraph as $part)
                                        @if(is_string($part))
                                            <span>{{ $part }}</span>
                                        @elseif(is_array($part) && is_string($part['text'] ?? null))
                                            @if($part['emphasis'] ?? false)
                                                <strong class="{{ $heroStyle['supporting_copy_highlight'] ?? 'text-primary' }}">
                                                    {{ $part['text'] }}
                                                </strong>
                                            @else
                                                <span>{{ $part['text'] }}</span>
                                            @endif
                                        @endif
                                    @endforeach
                                </p>
                            @endif
                        @endforeach
                    @elseif(is_string($heroSupportingCopy))
                        <p class="{{ $heroStyle['supporting_copy'] ?? '' }} text-lg font-semibold">
                            {{ $heroSupportingCopy }}
                        </p>
                    @endif
                </div>
            @endif

            @if(filled($heroClosingCopy))
                @foreach([
                    ['visibility' => 'hidden lg:block', 'spacing' => 'mt-5'],
                    ['visibility' => 'block lg:hidden', 'spacing' => 'mt-8'],
                ] as $closingCopyPlacement)
                    @if(is_array($heroClosingCopy))
                        <div class="{{ $closingCopyPlacement['visibility'] }} {{ $closingCopyPlacement['spacing'] }}">
                            @foreach($heroClosingCopy as $paragraph)
                                @if(is_string($paragraph))
                                    <p class="{{ $heroStyle['closing_copy'] ?? ($tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600') }}">
                                        {{ $paragraph }}
                                    </p>
                                @elseif(is_array($paragraph))
                                    <p class="{{ $heroStyle['closing_copy'] ?? ($tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600') }}">
                                        @foreach($paragraph as $part)
                                            @if(is_string($part))
                                                <span>{{ $part }}</span>
                                            @elseif(is_array($part) && is_string($part['text'] ?? null))
                                                @if($part['emphasis'] ?? false)
                                                    <strong class="{{ $heroStyle['closing_copy_highlight'] ?? 'text-primary' }}">
                                                        {{ $part['text'] }}
                                                    </strong>
                                                @else
                                                    <span>{{ $part['text'] }}</span>
                                                @endif
                                            @endif
                                        @endforeach
                                    </p>
                                @endif
                            @endforeach
                        </div>
                    @elseif(is_string($heroClosingCopy))
                        <p class="{{ $closingCopyPlacement['visibility'] }} {{ $heroStyle['closing_copy'] ?? ($tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600') }} {{ $closingCopyPlacement['spacing'] }}">
                            @if($heroClosingCopyHighlightPosition !== false)
                                {{ mb_substr($heroClosingCopy, 0, $heroClosingCopyHighlightPosition) }}<strong class="{{ $heroStyle['closing_copy_highlight'] ?? 'text-primary' }}">{{ $heroClosingCopyHighlight }}</strong>{{ mb_substr($heroClosingCopy, $heroClosingCopyHighlightPosition + mb_strlen($heroClosingCopyHighlight)) }}
                            @else
                                {{ $heroClosingCopy }}
                            @endif
                        </p>
                    @endif
                @endforeach
            @endif

            @if(filled($hero['authority_line'] ?? null))
                <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }} mt-5 font-extrabold">
                    {{ $hero['authority_line'] }}
                </p>
            @endif

            @if(filled($heroBullets['intro'] ?? null))
                <p class="{{ $heroStyle['bullets_intro'] ?? 'mt-6 mb-2 text-2xl font-semibold text-primary' }}">
                    {{ $heroBullets['intro'] }}
                </p>
            @endif

            @if($heroBulletItems !== [])
                <ul class="{{ $heroStyle['bullets_list'] ?? ($heroStyle['list'] ?? 'space-y-3') }}">
                    @foreach($heroBulletItems as $bullet)
                        @if(is_string($bullet))
                            <li class="{{ $heroStyle['bullet_item'] ?? ($heroStyle['list_item'] ?? 'ml-4 flex gap-3 text-base font-bold') }}">
                                <span class="{{ $heroStyle['bullet_icon'] ?? ($heroStyle['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white') }}">
                                    ✓
                                </span>
                                <span>{{ $bullet }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif

            @if($urgencyStats['enabled'] ?? false)
                <div class="{{ $urgencyStatsStyle['wrapper'] ?? 'mt-8' }}">
                    @if(filled($urgencyStats['intro'] ?? null))
                        <p class="{{ $urgencyStatsStyle['intro'] ?? 'mt-6 text-lg font-bold' }}">
                            {{ $urgencyStats['intro'] }}
                        </p>
                    @endif

                    <div class="{{ $urgencyStatsStyle['stats_wrapper'] ?? 'mt-4 grid gap-3 sm:grid-cols-3' }}">
                        @foreach($urgencyStatItems as $item)
                            @if(is_array($item))
                                <div class="{{ $urgencyStatsStyle['item'] ?? 'rounded-2xl p-5' }}">
                                    <span class="{{ $urgencyStatsStyle['value'] ?? 'block text-3xl font-extrabold' }}">
                                        {{ $item['value'] ?? '' }}
                                    </span>

                                    <span class="{{ $urgencyStatsStyle['label'] ?? 'mt-1 block text-sm font-bold' }}">
                                        {{ $item['label'] ?? '' }}
                                    </span>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if(filled($urgencyStats['closing_line'] ?? null))
                        <p class="{{ $urgencyStatsStyle['closing_line'] ?? 'mt-6 text-lg font-bold' }}">
                            {{ $urgencyStats['closing_line'] }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        @if($primaryCta['enabled'] ?? false)
            <div class="{{ $primaryCtaStyle['wrapper'] ?? 'mt-10 flex flex-col gap-4 text-left' }}">
                @if($webinarTitle['enabled'] ?? false)
                    <span class="{{ $webinarTitleStyle['contact'] ?? 'text-xl text-white/85' }}">
                        Seminar Details for the
                    </span>

                    <h1 class="{{ $webinarTitleStyle['title'] ?? 'text-4xl font-semibold text-white' }}">
                        {{ $series->title }}
                    </h1>
                @endif

                <x-webinars.event-details
                    :page="$page"
                    :items="$eventDetailsItems"
                    :style="$style"
                    :tokens="$tokens"
                />

                <div class="hidden {{ $primaryCtaStyle['countdown_split'] ?? 'lg:flex flex-col gap-4' }}">
                    @if(filled($primaryCta['pretext'] ?? null))
                        <p class="{{ $primaryCtaStyle['pretext'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                            {{ $primaryCta['pretext'] }}
                        </p>
                    @endif

                    @if(($countdown['enabled'] ?? false) && filled($countdownTarget))
                        <x-webinars.countdown
                            :content="$page"
                            :style="$style"
                            :target="$countdownTarget"
                            :theme="$heroStyle['theme'] ?? 'dark'"
                        />
                    @endif

                    <x-ui.button
                        type="button"
                        @click="formOpen = true"
                        class="{{ $tokens['primary_button'] ?? 'w-full' }}"
                    >
                        {{ $primaryCta['label'] ?? 'Save My Seat' }}
                    </x-ui.button>

                    @if(filled($primaryCta['helper_text'] ?? null))
                        <p class="{{ $primaryCtaStyle['helper_text'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                            {{ $primaryCta['helper_text'] }}
                        </p>
                    @endif

                    @if(filled($primaryCta['micro_trust'] ?? null))
                        <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                            {{ $primaryCta['micro_trust'] }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endif