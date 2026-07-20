@php
    $eventDetailsItems = collect($page['event_details']['items'] ?? [])->map(function (array $item) use ($webinar) {
        $key = $item['key'] ?? null;

        $resolvedValue = match ($key) {
            'date' => $webinar?->starts_at?->timezone($webinar->timezone ?? config('app.timezone'))->format('F j, Y'),
            'time' => $webinar?->starts_at?->timezone($webinar->timezone ?? config('app.timezone'))->format('g:i A'),
            default => $item['value'] ?? null,
        };

        return [
            ...$item,
            'resolved_value' => $resolvedValue,
        ];
    })->filter(fn (array $item) => filled($item['resolved_value'] ?? null))->values();
@endphp

<x-layouts.public
    :title="$page['meta_title'] ?? 'Webinar Registration'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section
        class="{{ $style['section'] ?? 'bg-white text-ink' }}"
        data-registration-status="{{ $registrationStatus }}"
    >
        @if($page['hero']['enabled'] ?? false)
            <div class="{{ $style['hero']['section'] ?? '' }}">
                <div class="{{ $style['hero']['inner'] ?? '' }}">
                    @if(filled($page['hero']['eyebrow'] ?? null))
                        <p class="{{ $style['hero']['eyebrow'] ?? '' }}">
                            {{ $page['hero']['eyebrow'] }}
                        </p>
                    @endif

                    @if(filled($page['hero']['title'] ?? null))
                        <h1 class="{{ $style['hero']['title'] ?? '' }}">
                            {{ $page['hero']['title'] }}
                        </h1>
                    @endif

                    @if(filled($page['hero']['body'] ?? null))
                        <p class="{{ $style['hero']['body'] ?? '' }}">
                            {{ $page['hero']['body'] }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        @if($page['next_steps']['enabled'] ?? false)
            <div class="{{ $style['next_steps']['section'] ?? '' }}">
                <div class="{{ $style['next_steps']['inner'] ?? '' }}">
                    @if(filled($page['next_steps']['heading'] ?? null))
                        <h2 class="{{ $style['next_steps']['heading'] ?? '' }}">
                            {{ $page['next_steps']['heading'] }}
                        </h2>
                    @endif

                    <div class="{{ $style['next_steps']['grid'] ?? '' }}">
                        @foreach(($page['next_steps']['items'] ?? []) as $item)
                            <article class="{{ $style['next_steps']['card'] ?? '' }}">
                                <h3 class="{{ $style['next_steps']['title'] ?? '' }}">
                                    {{ $item['title'] ?? '' }}
                                </h3>

                                <p class="{{ $style['next_steps']['body'] ?? '' }}">
                                    {{ $item['body'] ?? '' }}
                                </p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(($page['event_details']['enabled'] ?? false) && $eventDetailsItems->isNotEmpty())
            <div class="{{ $style['event_details']['section'] ?? '' }}">
                <div class="{{ $style['event_details']['inner'] ?? '' }}">
                    @if(filled($page['event_details']['heading'] ?? null))
                        <h2 class="{{ $style['event_details']['heading'] ?? '' }}">
                            {{ $page['event_details']['heading'] }}
                        </h2>
                    @endif

                    <div class="{{ $style['event_details']['grid'] ?? '' }}">
                        @foreach($eventDetailsItems as $item)
                            <div class="{{ $style['event_details']['card'] ?? '' }}">
                                <p class="{{ $style['event_details']['label'] ?? '' }}">
                                    {{ $item['label'] ?? '' }}
                                </p>

                                <p class="{{ $style['event_details']['value'] ?? '' }}">
                                    {{ $item['resolved_value'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(filled($page['actions'] ?? []))
            <div class="{{ $style['actions']['section'] ?? '' }}">
                <div class="{{ $style['actions']['wrapper'] ?? '' }}">
                    @foreach($page['actions'] as $action)
                        <x-ui.button
                            :href="route($action['route'])"
                            :variant="$action['variant'] ?? 'primary'"
                        >
                            {{ $action['label'] ?? 'Continue' }}
                        </x-ui.button>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    @if(is_int($refreshSeconds))
        <script>
            window.setTimeout(() => window.location.reload(), {{ $refreshSeconds * 1000 }});
        </script>
    @endif
</x-layouts.public>