@props([
    'content' => [],
    'style' => [],
    'target' => null,
    'theme' => 'dark',
    'countdown' => null,
])

@php
    $countdownContent = $countdown ?? ($content['countdown'] ?? []);

    $countdownStyle = $style['countdown']['themes'][$theme]
        ?? $style['countdown']['themes']['dark']
        ?? [];
@endphp

@if(($countdownContent['enabled'] ?? false) && filled($target))
    <div class="{{ $countdownStyle['wrapper'] ?? '' }}">
        <div class="{{ $countdownStyle['grid'] ?? 'grid grid-cols-4 gap-3 text-center' }}">
            @foreach(($countdownContent['items'] ?? $content['countdown']['items'] ?? []) as $item)
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