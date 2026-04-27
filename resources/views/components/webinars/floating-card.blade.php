@props([
    'content' => [],
    'style' => [],
    'target' => null,
])

@if(($content['sticky_desktop']['enabled'] ?? false))
    @php
        $card = $style['sticky_desktop'] ?? [];
        $cdTheme = 'light';

        $countdown = $style['countdown']['themes'][$cdTheme]
            ?? [];
    @endphp

    <div
        x-cloak
        x-show="showStickyCta"
        x-transition.opacity.duration.300ms
        class="{{ $card['wrapper'] ?? '' }}"
    >
        <p class="{{ $card['eyebrow'] ?? '' }}">
            {{ $content['sticky_desktop']['eyebrow'] ?? 'Next class starts in:' }}
        </p>

        <x-webinars.countdown
            :content="$content"
            :style="$style"
            :target="$target"
            theme="light"
        />

        <button
            type="button"
            @click="formOpen = true"
            class="{{ $card['cta'] ?? '' }}"
        >
            {{ $content['sticky_desktop']['label'] ?? 'Save My Seat' }}
        </button>

        <p class="{{ $card['helper_text'] ?? '' }}">
            {{ $content['sticky_desktop']['helper_text'] ?? '' }}
        </p>
    </div>
@endif