@props([
    'items' => [],
    'idPrefix' => 'disclosures',
    'style' => [],
])

@php
    $sourceItems = $items instanceof \Illuminate\Support\Collection
        ? $items->all()
        : (is_array($items) ? $items : []);
    $style = is_array($style) ? $style : [];
    $idPrefix = is_string($idPrefix)
        ? \Illuminate\Support\Str::slug(trim($idPrefix))
        : '';
    $idPrefix = $idPrefix !== '' ? $idPrefix : 'disclosures';

    $normalizedItems = collect($sourceItems)
        ->mapWithKeys(function (mixed $item, mixed $key) use ($idPrefix): array {
            if (! is_array($item)) {
                return [];
            }

            $resolvedKey = is_string($item['key'] ?? null)
                ? trim($item['key'])
                : (is_string($key) ? trim($key) : '');

            if ($resolvedKey === '') {
                return [];
            }

            $text = $item['text'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                return [];
            }

            $slug = \Illuminate\Support\Str::slug($resolvedKey);

            if ($slug === '') {
                return [];
            }

            $configuredId = $item['id'] ?? null;
            $configuredId = is_string($configuredId)
                ? trim($configuredId)
                : '';
            $resolvedId = preg_match(
                '/^[A-Za-z][A-Za-z0-9_.:-]*$/',
                $configuredId,
            ) === 1
                ? $configuredId
                : $idPrefix.'-'.$slug.'-disclosure';

            $marker = $item['marker'] ?? null;
            $marker = is_scalar($marker) ? trim((string) $marker) : null;

            $label = $item['label'] ?? null;
            $label = is_string($label) ? trim($label) : null;

            return [
                $resolvedKey => [
                    'key' => $resolvedKey,
                    'id' => $resolvedId,
                    'marker' => $marker !== '' ? $marker : null,
                    'label' => $label !== '' ? $label : null,
                    'text' => trim($text),
                ],
            ];
        })
        ->values();
@endphp

@if($normalizedItems->isNotEmpty())
    <div
        {{ $attributes->merge([
            'class' => $style['wrapper']
                ?? 'space-y-1 text-[11px] leading-5 text-slate-500',
        ]) }}
        role="note"
    >
        @foreach($normalizedItems as $item)
            <p
                id="{{ $item['id'] }}"
                class="{{ $style['item'] ?? 'grid grid-cols-[0.75rem_minmax(0,1fr)] items-start gap-x-1' }}"
            >
                @if(filled($item['marker']))
                    <sup
                        aria-hidden="true"
                        class="{{ $style['marker'] ?? 'font-semibold leading-5 text-slate-600' }}"
                    >{{ $item['marker'] }}</sup>
                    <span class="sr-only">Disclosure {{ $item['marker'] }}: </span>
                @endif

                <span>
                    @if(filled($item['label']))
                        <span class="{{ $style['label'] ?? 'font-semibold text-slate-600' }}">
                            {{ $item['label'] }}
                        </span>
                    @endif

                    <span class="{{ $style['text'] ?? '' }}">
                        {{ $item['text'] }}
                    </span>
                </span>
            </p>
        @endforeach
    </div>
@endif