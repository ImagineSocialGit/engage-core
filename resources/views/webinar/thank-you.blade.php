@php
    $page = config('theme.webinar_public.pages.thank_you', []);
    $tokens = config('theme.webinar_public.tokens', []);
@endphp

<x-layouts.public
    :title="$page['title'] ?? 'Thank You'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section class="{{ $page['section'] ?? 'mx-auto max-w-3xl px-6 py-20' }}">
        <div class="{{ config('theme.webinar_public.layout.card') ?? 'rounded-3xl border bg-white shadow-sm' }}">
            <div class="{{ config('theme.webinar_public.layout.card_padding') ?? 'p-8 sm:p-10' }} {{ $page['card']['align'] ?? 'text-center' }}">

                @if(filled($page['card']['eyebrow'] ?? null))
                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                        {{ $page['card']['eyebrow'] }}
                    </p>
                @endif

                <h1 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} mt-4">
                    {{ $page['card']['title'] ?? "You're registered" }}
                </h1>

                @if(filled($page['card']['body'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-6">
                        {{ $page['card']['body'] }}
                    </p>
                @endif

                @if(!empty($page['actions']))
                    <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:justify-center">
                        @foreach($page['actions'] as $action)
                            @php
                                $href = isset($action['route'])
                                    ? route($action['route'])
                                    : '#';
                            @endphp

                            @if(($action['variant'] ?? 'primary') === 'secondary')
                                <x-ui.button
                                    as="a"
                                    href="{{ $href }}"
                                    variant="secondary"
                                >
                                    {{ $action['label'] }}
                                </x-ui.button>
                            @else
                                <x-ui.button
                                    as="a"
                                    href="{{ $href }}"
                                >
                                    {{ $action['label'] }}
                                </x-ui.button>
                            @endif
                        @endforeach
                    </div>
                @endif

            </div>
        </div>
    </section>
</x-layouts.public>