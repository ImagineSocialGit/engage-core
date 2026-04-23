@php
    $page = config('theme.webinar_public.pages.index', []);
    $tokens = config('theme.webinar_public.tokens', []);
@endphp

<x-layouts.public
    :title="$page['title'] ?? 'Upcoming Webinars'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section class="{{ $page['section'] ?? 'mx-auto max-w-4xl px-6 py-20' }}">

        @if($page['hero']['enabled'] ?? true)
            <div class="{{ $page['hero']['wrapper'] ?? 'mx-auto max-w-4xl' }} {{ $page['hero']['align'] ?? 'text-center' }}">
                @if(filled($page['hero']['eyebrow'] ?? null))
                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                        {{ $page['hero']['eyebrow'] }}
                    </p>
                @endif

                <h1 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} mt-4">
                    {{ $page['hero']['title'] ?? 'Upcoming Webinars' }}
                </h1>

                @if(filled($page['hero']['body'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-6">
                        {{ $page['hero']['body'] }}
                    </p>
                @endif
            </div>
        @endif

        @if(($page['series_list']['enabled'] ?? true) && isset($seriesOptions) && count($seriesOptions))
            <div class="{{ $page['series_list']['wrapper'] ?? 'mt-12 rounded-3xl border bg-white p-8 shadow-sm' }}">
                @if(filled($page['series_list']['heading'] ?? null))
                    <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                        {{ $page['series_list']['heading'] }}
                    </h2>
                @endif

                <ul class="{{ $page['series_list']['list'] ?? 'mt-6 space-y-3' }}">
                    @foreach($seriesOptions as $option)
                        <li>
                            <a
                                href="{{ route('webinar.show', $option->slug) }}"
                                class="{{ $tokens['list_link'] ?? 'font-semibold underline underline-offset-4' }}"
                            >
                                {{ $option->title }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

    </section>
</x-layouts.public>