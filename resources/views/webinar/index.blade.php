@php
    $page = config('webinars.index.content', []);
    $style = config('webinars.index.style', []);
    $tokens = array_replace_recursive(
        config('webinars.index.style.tokens', []),
        $style['tokens'] ?? []
    );
@endphp

<x-layouts.public
    :title="$page['title'] ?? 'Webinar Series'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section class="{{ $style['section'] ?? 'mx-auto max-w-5xl px-6 py-16 sm:py-24' }}">

        @if($page['hero']['enabled'] ?? true)
            <div class="{{ $style['hero']['wrapper'] ?? 'mx-auto max-w-3xl' }} {{ $style['hero']['align'] ?? 'text-center' }}">
                @if(filled($page['hero']['eyebrow'] ?? null))
                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                        {{ $page['hero']['eyebrow'] }}
                    </p>
                @endif

                <h1 class="{{ $tokens['hero_title'] ?? 'mt-4 text-4xl font-bold tracking-tight text-white/95 sm:text-5xl' }}">
                    {{ $page['hero']['title'] ?? 'Webinar Series' }}
                </h1>

                @if(filled($page['hero']['body'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-6">
                        {{ $page['hero']['body'] }}
                    </p>
                @endif
            </div>
        @endif

        @if(($page['series_list']['enabled'] ?? true) && $series->isNotEmpty())
            <div class="{{ $style['series_list']['wrapper'] ?? 'mt-14 rounded-[2rem] border border-slate-200/80 bg-secondary/95 p-8 shadow-xl shadow-slate-200/60 backdrop-blur' }}">
                @if(filled($page['series_list']['heading'] ?? null))
                    <div class="{{ $style['series_list']['heading_wrapper'] ?? 'mb-8 text-center' }}">
                        <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight text-slate-900' }}">
                            {{ $page['series_list']['heading'] }}
                        </h2>
                    </div>
                @endif

                <ul class="{{ $style['series_list']['list'] ?? 'mt-8 grid gap-4 sm:grid-cols-2' }}">
                    @foreach($series as $seriesItem)
                        <li>
                            <a
                                href="{{ route('webinar.show', $seriesItem->slug) }}"
                                class="{{ $style['series_card']['wrapper'] ?? 'group block rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-6 transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg' }}"
                            >
                                <div class="{{ $style['series_card']['title'] ?? 'text-lg font-semibold tracking-tight text-slate-900 transition group-hover:text-slate-700' }}">
                                    {{ $seriesItem->title }}
                                </div>

                                @if(filled($seriesItem->description))
                                    <p class="{{ $style['series_card']['description'] ?? 'mt-2 text-sm leading-6 text-slate-600' }}">
                                        {{ $seriesItem->description }}
                                    </p>
                                @endif

                                <div class="{{ $style['series_card']['cta'] ?? 'mt-4 inline-flex items-center text-sm font-semibold text-slate-900' }}">
                                    {{ $page['series_card']['cta'] ?? 'View Webinar Series' }} →
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="{{ $style['empty_state']['wrapper'] ?? 'mt-14 rounded-4xl border border-slate-200 bg-secondary/95 p-10 text-center shadow-xl shadow-slate-200/60' }}">
                <h2 class="{{ $tokens['section_title'] ?? 'text-2xl font-bold tracking-tight text-slate-900' }}">
                    {{ $page['empty_state']['heading'] ?? 'No webinar series are available right now.' }}
                </h2>

                <p class="{{ $tokens['body'] ?? 'text-base leading-7 text-slate-600' }} mt-4">
                    {{ $page['empty_state']['body'] ?? 'Please check back soon for new webinar topics.' }}
                </p>
            </div>
        @endif

    </section>
</x-layouts.public>