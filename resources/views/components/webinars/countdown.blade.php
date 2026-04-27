@props([
    'content' => [],
    'style' => [],
    'target' => null,
    'theme' => 'dark',
])

@php
    $countdownContent = $content['countdown'] ?? [];
    $countdownStyle = $style['countdown']['themes'][$theme]
        ?? $style['countdown']['themes']['dark']
        ?? [];
@endphp

@if(($countdownContent['enabled'] ?? false) && filled($target))
    <div
        x-data="{
            countdownTarget: @js($target),
            remaining: 0,
            init() {
                if (!this.countdownTarget) return;
                this.tick();
                setInterval(() => this.tick(), 1000);
            },
            tick() {
                this.remaining = Math.max(0, new Date(this.countdownTarget).getTime() - Date.now());
            },
            days() {
                return Math.floor(this.remaining / 86400000);
            },
            hours() {
                return Math.floor((this.remaining % 86400000) / 3600000);
            },
            minutes() {
                return Math.floor((this.remaining % 3600000) / 60000);
            },
            seconds() {
                return Math.floor((this.remaining % 60000) / 1000);
            },
        }"
        class="{{ $countdownStyle['wrapper'] ?? '' }}"
    >
        <div class="{{ $countdownStyle['grid'] ?? 'grid grid-cols-4 gap-3 text-center' }}">
            @foreach(($countdownContent['items'] ?? []) as $item)
                <div class="{{ $countdownStyle['item'] ?? '' }}">
                    <p
                        class="{{ $countdownStyle['value'] ?? '' }}"
                        x-text="{{ $item['method'] ?? 'days' }}().toString().padStart(2, '0')"
                    ></p>

                    <p class="{{ $countdownStyle['unit'] ?? '' }}">
                        {{ $item['label'] ?? '' }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
@endif