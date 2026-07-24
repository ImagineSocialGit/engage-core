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
    $heroClosingCopy = is_string($page['hero']['closing_copy'] ?? null)
        ? $page['hero']['closing_copy']
        : null;
    $heroClosingCopyHighlight = is_string($page['hero']['closing_copy_highlight'] ?? null)
        ? $page['hero']['closing_copy_highlight']
        : null;
    $heroClosingCopyHighlightPosition = filled($heroClosingCopy)
        && filled($heroClosingCopyHighlight)
            ? mb_strpos($heroClosingCopy, $heroClosingCopyHighlight)
            : false;
@endphp

@if($page['hero']['enabled'] ?? true)
<div
    x-ref="heroSection" 
    class="{{ $style['hero']['section'] ?? 'bg-secondary text-white' }}">
    <div class="{{ $style['hero']['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-14 sm:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center' }}">
        <div class="{{ $style['hero']['wrapper'] ?? 'max-w-4xl text-left' }} {{ $style['hero']['align'] ?? 'text-left' }}">
            @if(filled($page['hero']['eyebrow'] ?? null))
                <p class="{{ $style['hero']['eyebrow'] ?? ($tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]') }}">
                    {{ $page['hero']['eyebrow'] }}
                </p>
            @endif

            <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} {{ $style['hero']['title'] ?? 'mt-4' }}">

                <span class="block">
                    {{ $page['hero']['title'] ?? $page['hero']['title_prefix'] ?? 'Register for Webinar' }}
                </span>

                @if(filled($page['hero']['subtitle'] ?? null))
                    <span class="{{ $style['hero']['subtitle'] ?? 'mt-2 block text-[0.92em] text-[#6f9fd9]' }}">
                        {{ $page['hero']['subtitle'] }}
                    </span>

                @elseif(blank($page['hero']['title'] ?? null))
                    <span class="block">
                        {{ $series->title }}
                    </span>
                @endif

            </h2>

            @if(filled($page['hero']['body']))
                @if (is_array($page['hero']['body']))                    
                @foreach(($page['hero']['body'] ?? []) as $paragraph)
                <div class="{{ $style['hero']['body'] ?? 'text-lg leading-8 text-white' }}">
                    @if(is_array($paragraph))
                        <p class="space-x-1">
                        @foreach($paragraph as $part)
                            @if($part['emphasis'] ?? false)
                                <strong class="text-primary">
                                    {{ $part['text'] ?? '' }}
                                </strong>
                            @else
                                <span>{{ $part['text'] ?? '' }}</span>
                            @endif
                        @endforeach
                        </p>
                    @else
                        {{ $paragraph }}
                    @endif
                </div>
                @endforeach
                @else
                    {{ $page['hero']['body'] }}
                @endif
            @endif

            @if(filled($page['hero']['supporting_copy']))
                @if (is_array($page['hero']['supporting_copy']))                    
                @foreach(($page['hero']['supporting_copy'] ?? []) as $paragraph)
                <div class="{{ $style['hero']['supporting_copy'] ?? 'text-lg leading-8 text-white' }}">
                    @if(is_array($paragraph))
                        <p>
                        @foreach($paragraph as $part)
                            @if($part['emphasis'] ?? false)
                                <strong class="text-primary">
                                    {{ $part['text'] ?? '' }}
                                </strong>
                            @else
                                <span>{{ $part['text'] ?? '' }}</span>
                            @endif
                        @endforeach
                        </p>
                    @else
                        {{ $paragraph }}
                    @endif
                </div>
                @endforeach
                @else
                    {{ $page['hero']['supporting_copy'] }}
                @endif
            @endif

            @if(filled($page['hero']['closing_copy']))
                @if (is_array($page['hero']['closing_copy']))                    
                @foreach(($page['hero']['closing_copy'] ?? []) as $paragraph)
                <div class="{{ $style['hero']['closing_copy'] ?? 'text-lg leading-8 text-white' }}">
                    @if(is_array($paragraph))
                        <p>
                        @foreach($paragraph as $part)
                            @if($part['emphasis'] ?? false)
                                <strong class="text-primary">
                                    {{ $part['text'] ?? '' }}
                                </strong>
                            @else
                                <span>{{ $part['text'] ?? '' }}</span>
                            @endif
                        @endforeach
                        </p>
                    @else
                        {{ $paragraph }}
                    @endif
                </div>
                @endforeach
                @else
                <p>{{ $page['hero']['closing_copy'] }}</p>
                @endif
            @endif

            @if(filled($page['hero']['authority_line'] ?? null))
                <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }} mt-5 font-extrabold">
                    {{ $page['hero']['authority_line'] }}
                </p>
            @endif

            @if(filled($page['hero']['bullets'] ?? []))
                <p class="text-2xl font-semibold text-primary mt-6 mb-2">{{ $page['hero']['bullets']['intro']}}</p>
                <ul class="{{ $style['hero']['list'] ?? 'space-y-3' }}">
                    @foreach($page['hero']['bullets']['list'] as $bullet)
                        <li class="{{ $style['hero']['list_item'] ?? 'ml-4 flex gap-3 text-base font-bold' }}">
                            <span class="{{ $style['hero']['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                            <span>{{ $bullet }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($page['urgency_stats']['enabled'] ?? false)
                <div class="{{ $style['urgency_stats']['wrapper'] ?? 'mt-8' }}">
                    @if(filled($page['urgency_stats']['intro'] ?? null))
                        <p class="{{ $style['urgency_stats']['intro'] ?? 'mt-6 text-lg font-bold' }}">
                            {{ $page['urgency_stats']['intro'] }}
                        </p>
                    @endif

                    <div class="{{ $style['urgency_stats']['stats_wrapper'] ?? 'mt-4 grid gap-3 sm:grid-cols-3' }}">
                        @foreach(($page['urgency_stats']['items'] ?? []) as $item)
                            <div class="{{ $style['urgency_stats']['item'] ?? 'rounded-2xl p-5' }}">
                                <span class="{{ $style['urgency_stats']['value'] ?? 'block text-3xl font-extrabold' }}">
                                    {{ $item['value'] ?? '' }}
                                </span>

                                <span class="{{ $style['urgency_stats']['label'] ?? 'mt-1 block text-sm font-bold' }}">
                                    {{ $item['label'] ?? '' }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    @if(filled($page['urgency_stats']['closing_line'] ?? null))
                        <p class="{{ $style['urgency_stats']['closing_line'] ?? 'mt-6 text-lg font-bold' }}">
                            {{ $page['urgency_stats']['closing_line'] }}
                        </p>
                    @endif
                </div>
            @endif

            @if(filled($heroClosingCopy))
                <p class="block lg:hidden {{ $style['hero']['closing_copy'] ?? ($tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600') }} mt-8">
                    @if($heroClosingCopyHighlightPosition !== false)
                        {{ mb_substr($heroClosingCopy, 0, $heroClosingCopyHighlightPosition) }}<strong class="{{ $style['hero']['closing_copy_highlight'] ?? 'text-primary' }}">{{ $heroClosingCopyHighlight }}</strong>{{ mb_substr($heroClosingCopy, $heroClosingCopyHighlightPosition + mb_strlen($heroClosingCopyHighlight)) }}
                    @else
                        {{ $heroClosingCopy }}
                    @endif
                </p>
            @endif
        </div>

        @if($page['primary_cta']['enabled'] ?? false)
            <div class="{{ $style['primary_cta']['wrapper'] ?? 'mt-10 flex flex-col gap-4 text-left' }}">

                @if (($page['webinar_title']['enabled'] ?? false))
                    <span class="{{ $style['webinar_title']['contact'] ?? 'text-xl text-white/85' }}">
                        Seminar Details for the
                    </span>
                    <h1 class="{{ $style['webinar_title']['title'] }}">
                        {{ $series->title }}
                    </h1>
                @endif

                <x-webinars.event-details
                    :page="$page"
                    :items="$eventDetailsItems"
                    :style="$style"
                    :tokens="$tokens"
                />

                <div class="hidden {{ $style['primary_cta']['countdown_split'] ?? 'lg:flex flex-col gap-4' }}">

                    @if(filled($page['primary_cta']['pretext'] ?? null))
                        <p class="{{ $style['primary_cta']['pretext'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                            {{ $page['primary_cta']['pretext'] }}
                        </p>
                    @endif

                    @if(($page['countdown']['enabled'] ?? false) && filled($countdownTarget))
                        <x-webinars.countdown
                            :content="$page"
                            :style="$style"
                            :target="$countdownTarget"
                            :theme="$style['hero']['theme']"
                        />
                    @endif

                    <x-ui.button
                        type="button"
                        @click="formOpen = true"
                        class="{{ $tokens['primary_button'] ?? 'w-full' }}"
                    >
                        {{ $page['primary_cta']['label'] ?? 'Save My Seat' }}
                    </x-ui.button>

                    @if(filled($page['primary_cta']['helper_text'] ?? null))
                        <p class="{{ $style['primary_cta']['helper_text'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                            {{ $page['primary_cta']['helper_text'] }}
                        </p>
                    @endif

                    @if(filled($page['primary_cta']['micro_trust'] ?? null))
                        <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                            {{ $page['primary_cta']['micro_trust'] }}
                        </p>
                    @endif

                </div>
            </div>
        @endif

    </div>
</div>
@endif