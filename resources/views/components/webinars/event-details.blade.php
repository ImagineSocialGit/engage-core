@props([
    'page',
    'items',
    'style',
    'tokens',
])

@if(($page['event_details']['enabled'] ?? false) && $items->isNotEmpty())
    <div class="{{ $style['event_details']['wrapper'] ?? 'mt-16 lg:col-span-2' }}">
        @if(filled($page['event_details']['heading'] ?? null))
            <div class="{{ $style['event_details']['heading_class'] ?? 'text-center' }}">
                <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                    {{ $page['event_details']['heading'] }}
                </h2>
            </div>
        @endif

        <div class="{{ $style['event_details']['grid'] ?? 'mt-8 grid gap-4 md:grid-cols-3' }}">
            @foreach($items as $item)
                <div class="{{ $style['event_details']['card'] ?? 'rounded-2xl border bg-white p-6 shadow-sm' }}">
                    <p class="{{ $style['event_details']['label'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                        {{ $item['label'] ?? '' }}
                    </p>

                    <p class="{{ $style['event_details']['value'] ?? 'mt-3 text-xl font-bold tracking-tight' }}">
                        {{ $item['resolved_value'] }}
                    </p>
                </div>
            @endforeach
        </div>

        @if(filled($page['event_details']['footnote'] ?? null))
            <p class="{{ $style['event_details']['footnote'] ?? ($tokens['muted'] ?? 'mt-6 text-sm') }}">
                {{ $page['event_details']['footnote'] }}
            </p>
        @endif
    </div>
@endif