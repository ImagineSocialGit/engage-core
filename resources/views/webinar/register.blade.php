@php
    $style = array_replace_recursive(
        config('webinars.style', []),
        config('webinars.register.style', []),
    );

    $page = array_replace_recursive(
        config('webinars.content', []),
        config('webinars.register.content', []),
        $series->meta['public_page'] ?? [],
    );

    $tokens = $style['tokens'] ?? [];
    $webinar = $series->nextUpcomingWebinar();
    $countdownTarget = $webinar?->starts_at?->timezone('UTC')->toIso8601String();

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
    :title="$page['title'] ?? 'Register for Webinar'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section
        x-data="{
            formOpen: {{ $errors->any() ? 'true' : 'false' }},
            exitIntentOpen: false,
            exitIntentShown: false,
            showStickyCta: false,
            handleMouseLeave(event) {
                if (!({{ ($page['exit_intent']['enabled'] ?? false) ? 'true' : 'false' }})) return;
                if (this.exitIntentShown) return;
                if (event.clientY > 0) return;

                this.exitIntentShown = true;
                this.exitIntentOpen = true;
            },
            countdownTarget: @js($countdownTarget),
            remaining: 0,
            initCountdown() {
                if (!this.countdownTarget) return;

                this.tickCountdown();
                setInterval(() => this.tickCountdown(), 1000);
            },
            tickCountdown() {
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
        x-init="
            initCountdown();
            const observer = new IntersectionObserver(([entry]) => {
                showStickyCta = !entry.isIntersecting;
            }, { threshold: 0 });

            $nextTick(() => {
                if ($refs.heroSection) observer.observe($refs.heroSection);
            });
        "
        @keydown.escape.window="formOpen = false; exitIntentOpen = false"
        @mouseleave.window="handleMouseLeave($event)"
        class="{{ $style['section'] ?? 'bg-white' }}"
    >
        @if($page['hero']['enabled'] ?? true)
            @php
                $heroTheme = $style['hero']['theme'] ?? 'dark';
                $heroCountdown = $style['countdown']['themes'][$heroTheme] ?? $style['countdown']['themes']['dark'];
            @endphp
            <div
                x-ref="heroSection" 
                class="{{ $style['hero']['section'] ?? 'bg-secondary text-white' }}">
                <div class="{{ $style['hero']['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-14 sm:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center' }}">
                    <div class="{{ $style['hero']['wrapper'] ?? 'max-w-4xl text-left' }} {{ $style['hero']['align'] ?? 'text-left' }}">
                        @if(filled($page['hero']['eyebrow'] ?? null))
                            <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                                {{ $page['hero']['eyebrow'] }}
                            </p>
                        @endif

                        <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} {{ $style['hero']['title'] ?? 'mt-4 flex flex-col gap-4' }}">
                            <span>
                                {{ $page['hero']['title'] ?? $page['hero']['title_prefix'] ?? 'Register for Webinar' }}
                            </span>

                            @if(filled($page['hero']['subtitle'] ?? null))
                                <span class="text-3xl sm:text-4xl">
                                    {{ $page['hero']['subtitle'] }}
                                </span>
                            @elseif(blank($page['hero']['title'] ?? null))
                                <span>
                                    {{ $series->title }}
                                </span>
                            @endif
                        </h2>

                        @if(filled($page['hero']['body'] ?? null))
                            <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} {{ $style['hero']['body'] ?? 'mt-6' }}">
                                {{ $page['hero']['body'] }}
                            </p>
                        @endif

                        @if(filled($page['hero']['supporting_copy'] ?? null))
                            @foreach ($page['hero']['supporting_copy'] as $item)
                            <p class="{{ $style['hero']['supporting_copy'] ?? 'mt-4' }}{{ $loop->last ? ' text-lg font-semibold' : ' text-base' }}">
                                {{ $item }}
                            </p>                                
                            @endforeach

                        @endif

                        @if(filled($page['hero']['closing_copy'] ?? null))
                            <p class="hidden lg:block {{ $tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600' }} mt-5">
                                {{ $page['hero']['closing_copy'] }}
                            </p>
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

                        @if(filled($page['hero']['closing_copy'] ?? null))
                            <p class="block lg:hidden {{ $tokens['emphasize'] ?? 'text-lg leading-8 text-slate-600' }} mt-8">
                                {{ $page['hero']['closing_copy'] }}
                            </p>
                        @endif
                    </div>

                    @if($page['primary_cta']['enabled'] ?? false)
                        <div class="{{ $style['primary_cta']['wrapper'] ?? 'mt-10 flex flex-col gap-4 text-left' }}">

                            @if (($page['webinar_title']['enabled'] ?? false))
                                <span class="{{ $style['webinar_title']['lead'] ?? 'text-xl text-white/85' }}">
                                    Seminar Details for the
                                </span>
                                <h1 class="{{ $style['webinar_title']['title'] }}">
                                    {{ $series->title }}
                                </h1>
                            @endif

                            @if(($page['event_details']['enabled'] ?? false) && $eventDetailsItems->isNotEmpty())
                                <div class="{{ $style['event_details']['wrapper'] ?? 'mt-16 lg:col-span-2' }}">
                                    @if(filled($page['event_details']['heading'] ?? null))
                                        <div class="{{ $style['event_details']['heading_class'] ?? 'text-center' }}">
                                            <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                                                {{ $page['event_details']['heading'] }}
                                            </h2>
                                        </div>
                                    @endif

                                    <div class="{{ $style['event_details']['grid'] ?? 'mt-8 grid gap-4 md:grid-cols-3' }}">
                                        @foreach($eventDetailsItems as $item)
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

                            <div class="hidden {{ $style['primary_cta']['countdown_split'] ?? 'lg:flex flex-col gap-4' }}">

                                @if(filled($page['primary_cta']['pretext'] ?? null))
                                    <p class="{{ $style['primary_cta']['pretext'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                                        {{ $page['primary_cta']['pretext'] }}
                                    </p>
                                @endif

                                @if(($page['countdown']['enabled'] ?? false) && filled($countdownTarget))
                                    <div class="{{ $heroCountdown['wrapper'] ?? 'rounded-2xl border border-white/10 bg-white/10 px-5 py-4' }}">
                                        @if(filled($page['countdown']['label'] ?? null))
                                            <p class="{{ $heroCountdown['label_class'] ?? 'mb-2 text-xs uppercase' }}">
                                                {{ $page['countdown']['label'] }}
                                            </p>
                                        @endif

                                        <div class="{{ $heroCountdown['grid'] ?? 'grid grid-cols-4 gap-3 text-center' }}">
                                            @foreach(($page['countdown']['items'] ?? []) as $item)
                                                <div class="{{ $heroCountdown['item'] ?? 'min-w-12' }}">
                                                    <p
                                                        class="{{ $heroCountdown['value'] ?? 'text-xl font-bold tabular-nums' }}"
                                                        x-text="{{ $item['method'] ?? 'days' }}().toString().padStart(2, '0')"
                                                    ></p>

                                                    <p class="{{ $heroCountdown['unit'] ?? 'mt-1 text-[0.65rem] uppercase' }}">
                                                        {{ $item['label'] ?? '' }}
                                                    </p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
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
        <div>
            @if(($page['problem']['enabled'] ?? false) || ($page['instructor']['enabled'] ?? false))
            
                <div class="{{ $style['problem']['section'] ?? 'bg-white text-ink' }}">
                    <div class="{{ $style['problem']['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-12 px-6 py-16 sm:py-24 lg:grid-cols-2 lg:items-center' }}">
                        @if($page['problem']['enabled'] ?? false)
                            <div class="{{ $style['problem']['content_wrapper'] ?? 'space-y-6' }}">
                                @if(filled($page['problem']['eyebrow'] ?? null))
                                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                                        {{ $page['problem']['eyebrow'] }}
                                    </p>
                                @endif

                                @if(filled($page['problem']['heading'] ?? null))
                                    <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                                        {{ $page['problem']['heading'] }}
                                    </h2>
                                @endif

                                @foreach(($page['problem']['body'] ?? []) as $paragraph)
                                <p class="{{ $style['problem']['paragraph'] ?? 'text-lg leading-8 text-ink' }}">
                                    @if(is_array($paragraph))
                                        @foreach($paragraph as $part)
                                            @if($part['emphasis'] ?? false)
                                                <strong class="{{ $style['problem']['paragraph_emphasis'] ?? 'font-black text-primary' }}">
                                                    {{ $part['text'] ?? '' }}
                                                </strong>
                                            @else
                                                <span>{{ $part['text'] ?? '' }}</span>
                                            @endif
                                        @endforeach
                                    @else
                                        {{ $paragraph }}
                                    @endif
                                </p>
                                @endforeach

                                @if(filled($page['problem']['bullets'] ?? []))
                                    <p class="text-2xl font-semibold">{{ $page['problem']['bullets']['intro']}}</p>
                                    <ul class="{{ $style['problem']['list'] ?? 'space-y-3' }}">
                                        @foreach($page['problem']['bullets']['list'] as $bullet)
                                            <li class="{{ $style['problem']['list_item'] ?? 'flex gap-3 text-base font-bold' }}">
                                                <span class="{{ $style['problem']['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                                                <span>{{ $bullet }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif

                        @if($page['instructor']['enabled'] ?? false)
                            <div class="{{ $style['instructor']['wrapper'] ?? 'rounded-3xl border border-black/10 bg-soft p-6 shadow-xl shadow-black/10 sm:p-8' }}">
                                @if(filled($page['instructor']['image'] ?? null))
                                    <div class="{{ $style['instructor']['image_wrapper'] ?? 'mx-auto max-w-md' }}">
                                        <x-ui.image
                                            :path="$page['instructor']['image']"
                                            :alt="$page['instructor']['image_alt'] ?? 'Instructor'"
                                            :sizes="$page['instructor']['image_sizes'] ?? '(min-width:1024px) 40vw,100vw'"
                                            class="{{ $style['instructor']['image_class'] ?? 'w-full rounded-3xl object-cover' }}"
                                            :placeholder="false"
                                        />

                                        @if(filled($page['instructor']['image_caption'] ?? null))
                                            <p class="{{ $tokens['muted_dark'] ?? 'text-sm text-slate-500' }} mt-4 text-center">
                                                {{ $page['instructor']['image_caption'] }}
                                            </p>
                                        @endif
                                    </div>
                                @endif

                                <div class="{{ $style['instructor']['content_wrapper'] ?? 'mt-8 space-y-4' }}">
                                    @if(filled($page['instructor']['eyebrow'] ?? null))
                                        <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                                            {{ $page['instructor']['eyebrow'] }}
                                        </p>
                                    @endif

                                    @if(filled($page['instructor']['heading'] ?? null))
                                        <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                                            {{ $page['instructor']['heading'] }}
                                        </h2>
                                    @endif

                                    @foreach(($page['instructor']['body'] ?? []) as $paragraph)
                                    <p class="{{ $style['instructor']['body'] ?? 'space-y-4 text-base font-medium leading-7 text-ink' }}">
                                        @if(is_array($paragraph))
                                            @foreach($paragraph as $part)
                                                @if($part['emphasis'] ?? false)
                                                    <strong class="{{ $style['instructor']['paragraph_emphasis'] ?? 'font-black text-primary' }}">
                                                        {{ $part['text'] ?? '' }}
                                                    </strong>
                                                @else
                                                    <span>{{ $part['text'] ?? '' }}</span>
                                                @endif
                                            @endforeach
                                        @else
                                            {{ $paragraph }}
                                        @endif
                                    </p>
                                    @endforeach

                                    @if(filled($page['instructor']['credibility'] ?? []))
                                        <ul class="{{ $style['instructor']['credibility_list'] ?? 'mt-6 grid gap-3' }}">
                                            @foreach($page['instructor']['credibility'] as $item)
                                                <li class="{{ $style['instructor']['credibility_item'] ?? 'flex gap-3 text-base font-extrabold text-ink' }}">
                                                    <span class="{{ $style['problem']['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                                                    <span>{{ $item }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if($page['secondary_cta']['enabled'] ?? false)
                <div class="{{ $style['secondary_cta']['wrapper'] ?? 'bg-white px-6 pb-16 text-center sm:pb-24' }}">
                    <div class="{{ $style['secondary_cta']['inner'] ?? 'mx-auto max-w-3xl' }}">
                        @if(filled($page['secondary_cta']['headline'] ?? null))
                            <h2 class="{{ $style['secondary_cta']['headline'] ?? ($tokens['section_title'] ?? 'text-3xl font-bold tracking-tight') }}">
                                {{ $page['secondary_cta']['headline'] }}
                            </h2>
                        @endif

                        <div class="mt-6 flex flex-col items-center gap-4">
                            <x-ui.button
                                type="button"
                                @click="formOpen = true"
                                class="{{ $tokens['secondary_button'] ?? 'w-full' }}"
                                
                            >
                                {{ $page['secondary_cta']['label'] ?? 'Reserve Your Spot Now' }}
                            </x-ui.button>

                            @if(filled($page['secondary_cta']['helper_text'] ?? null))
                                <p class="{{ $style['secondary_cta']['helper_text'] ?? ($tokens['muted_dark'] ?? 'text-sm text-slate-500') }}">
                                    {{ $page['secondary_cta']['helper_text'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if($page['trust']['enabled'] ?? false)
                <div class="{{ $style['trust']['wrapper'] ?? 'bg-secondary px-6 py-16 text-center text-white sm:py-24' }}">
                    <div class="{{ $style['trust']['inner'] ?? 'mx-auto max-w-6xl' }}">
                        @if(filled($page['trust']['headline'] ?? null))
                            <h2 class="{{ $style['trust']['headline'] ?? ($tokens['dark_section_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl') }}">
                                {{ $page['trust']['headline'] }}
                            </h2>
                        @endif

                        @if(filled($page['trust']['body'] ?? null))
                            <p class="{{ $style['trust']['body'] ?? ($tokens['body'] ?? 'text-lg leading-8 text-slate-600') }}">
                                {{ $page['trust']['body'] }}
                            </p>
                        @endif

                        @if(filled($page['trust']['reviews'] ?? []))
                            <div class="{{ $style['trust']['reviews_grid'] ?? 'mt-10 grid gap-5 md:grid-cols-3' }}">
                                @foreach($page['trust']['reviews'] as $review)
                                    <article class="{{ $style['trust']['review_card'] ?? 'rounded-3xl border border-white/10 bg-white/[0.06] p-6 text-left' }}">
                                        @if(filled($review['stars'] ?? null))
                                            <p class="{{ $style['trust']['stars'] ?? 'text-lg font-extrabold tracking-[0.18em] text-primary' }}">
                                                {{ $review['stars'] }}
                                            </p>
                                        @endif

                                        @if(filled($review['name'] ?? null))
                                            <h3 class="{{ $style['trust']['review_name'] ?? 'mt-4 text-base font-extrabold text-white' }}">
                                                {{ $review['name'] }}
                                            </h3>
                                        @endif

                                        @if(filled($review['text'] ?? null))
                                            <p class="{{ $style['trust']['review_text'] ?? 'mt-2 text-sm font-medium leading-6 text-white/75' }}">
                                                {{ $review['text'] }}
                                            </p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        @if(filled($page['trust']['review_url'] ?? null))
                            <div class="mt-6">
                                <a
                                    href="{{ $page['trust']['review_url'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="{{ $tokens['list_link'] ?? 'font-semibold underline underline-offset-4' }}"
                                >
                                    {{ $page['trust']['review_label'] ?? 'View Reviews' }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if($page['final_close']['enabled'] ?? false)
                @php
                    $finalCloseTheme = $style['final_close']['theme'] ?? 'light';
                    $finalCloseCountdown = $style['countdown']['themes'][$finalCloseTheme] ?? $style['countdown']['themes']['dark'];
                @endphp
                <div
                    x-data="{
                        countdownTarget: @js($countdownTarget),
                        remaining: 0,
                        init() {
                            if (!this.countdownTarget) return;
                            this.tick();
                            setInterval(() => this.tick(), 1000);
                        },
                        tick() {
                            this.remaining = Math.max(0, new Date(this.countdownTarget).getTime() - Date.now());
                        },
                        days() { return Math.floor(this.remaining / 86400000); },
                        hours() { return Math.floor((this.remaining % 86400000) / 3600000); },
                        minutes() { return Math.floor((this.remaining % 3600000) / 60000); },
                        seconds() { return Math.floor((this.remaining % 60000) / 1000); },
                    }"
                    class="{{ $style['final_close']['wrapper'] ?? 'bg-secondary px-6 pb-20 text-white sm:pb-28' }}"
                >
                    <div class="{{ $style['final_close']['inner'] ?? 'mx-auto max-w-4xl text-center' }}">
                        @if(filled($page['final_close']['headline'] ?? null))
                            <h2 class="{{ $style['final_close']['headline'] ?? ($tokens['dark_section_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl') }}">
                                {{ $page['final_close']['headline'] }}
                            </h2>
                        @endif

                        @if(filled($page['final_close']['bullets'] ?? []))
                        <div class="flex flex-col max-w-md mx-auto">
                            <p class="text-2xl font-semibold text-primary mt-6 mb-2">{{ $page['final_close']['bullets']['intro']}}</p>
                            <ul class="{{ $style['final_close']['list'] ?? 'space-y-3 ml-12' }}">
                                @foreach($page['final_close']['bullets']['list'] as $bullet)
                                    <li class="{{ $style['final_close']['list_item'] ?? 'ml-4 flex gap-3 text-base font-bold' }}">
                                        <span class="{{ $style['final_close']['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                                        <span>{{ $bullet }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif

                        @if(filled($page['final_close']['body'] ?? null))
                            <p class="mt-8 text-lg leading-8 text-ink">
                                {{ $page['final_close']['body'] }}
                            </p>
                        @endif

                        @if(filled($page['final_close']['closing_copy'] ?? null))
                            <p class="text-lg leading-8 text-ink">
                                {{ $page['final_close']['closing_copy'] }}
                            </p>
                        @endif

                        @if(($page['final_close']['countdown']['enabled'] ?? false) && filled($countdownTarget))
                            <div class="mx-auto mt-10 max-w-md">
                                <div class="{{ $finalCloseCountdown['wrapper'] ?? 'rounded-2xl border border-white/10 bg-white/10 px-5 py-4' }}">
                                    @if(filled($page['countdown']['label'] ?? null))
                                        <p class="{{ $style['countdown']['label_class'] ?? 'mb-2 text-xs uppercase' }}">
                                            {{ $page['countdown']['label'] }}
                                        </p>
                                    @endif

                                    <div class="{{ $style['countdown']['grid'] ?? 'grid grid-cols-4 gap-3 text-center' }}">
                                        @foreach(($page['countdown']['items'] ?? []) as $item)
                                            <div class="{{ $style['countdown']['item'] ?? 'min-w-12' }}">
                                                <p
                                                    class="{{ $style['countdown']['value'] ?? 'text-xl font-bold tabular-nums' }}"
                                                    x-text="{{ $item['method'] ?? 'days' }}().toString().padStart(2, '0')"
                                                ></p>

                                                <p class="{{ $style['countdown']['unit'] ?? 'mt-1 text-[0.65rem] uppercase' }}">
                                                    {{ $item['label'] ?? '' }}
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="{{ $style['final_close']['cta_wrapper'] ?? 'mt-10 flex flex-col items-center gap-4' }}">
                            <x-ui.button
                                type="button"
                                @click="formOpen = true"
                                class="{{ $tokens['secondary_button'] ?? 'w-full' }}"
                            >
                                {{ $page['final_close']['label'] ?? 'Lock In My Spot Now' }}
                            </x-ui.button>

                            @if(filled($page['final_close']['helper_text'] ?? null))
                                <p class="{{ $style['final_close']['helper_text'] ?? ($tokens['muted'] ?? 'text-sm text-white/65') }}">
                                    {{ $page['final_close']['helper_text'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if($page['compliance']['enabled'] ?? false)
                <div class="{{ $style['compliance']['wrapper'] ?? 'bg-secondary px-6 pb-10 text-center' }}">
                    <p class="{{ $style['compliance']['text'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                        {{ $page['compliance']['text'] ?? '' }}
                    </p>
                </div>
            @endif

            @if($page['exit_intent']['enabled'] ?? false)
                <div
                    x-cloak
                    x-show="exitIntentOpen"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-105"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-105"
                    class="fixed inset-0 z-60 flex items-center justify-center p-4"
                    aria-modal="true"
                    role="dialog"
                >
                    <div
                        class="absolute inset-0 bg-black/75"
                        @click="exitIntentOpen = false"
                    ></div>

                    <div class="relative z-10 w-full max-w-lg rounded-3xl bg-white p-8 text-center shadow-2xl">
                        <button
                            type="button"
                            @click="exitIntentOpen = false"
                            class="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                            aria-label="Close exit intent popup"
                        >
                            <span aria-hidden="true">&times;</span>
                        </button>

                        @if(filled($page['exit_intent']['headline'] ?? null))
                            <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight text-ink' }}">
                                {{ $page['exit_intent']['headline'] }}
                            </h2>
                        @endif

                        <div class="mt-6">
                            <x-ui.button
                                type="button"
                                @click="exitIntentOpen = false; formOpen = true"
                                class="{{ $tokens['secondary_button'] ?? 'w-full' }}"
                            >
                                {{ $page['exit_intent']['label'] ?? 'Let Me In' }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            @endif
            
        </div>

        @if($page['primary_cta']['enabled'] ?? false)
            @php
                $heroTheme = $style['hero']['theme'] ?? 'dark';
                $heroCountdown = $style['countdown']['themes'][$heroTheme] ?? $style['countdown']['themes']['dark'];
            @endphp
            <div class="{{ $style['mobile_after_hero_cta']['wrapper'] ?? 'lg:hidden flex flex-col gap-3 bg-secondary px-4 py-4' }}">
                @if(filled($page['primary_cta']['pretext'] ?? null))
                    <p class="{{ $style['primary_cta']['pretext'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                        {{ $page['primary_cta']['pretext'] }}
                    </p>
                @endif

                @if(($page['countdown']['enabled'] ?? false) && filled($countdownTarget))
                    <div class="{{ $heroCountdown['wrapper'] ?? 'rounded-2xl border border-white/10 bg-white/10 px-5 py-4' }}">
                        @if(filled($page['countdown']['label'] ?? null))
                            <p class="{{ $heroCountdown['label_class'] ?? 'mb-2 text-xs uppercase' }}">
                                {{ $page['countdown']['label'] }}
                            </p>
                        @endif

                        <div class="{{ $heroCountdown['grid'] ?? 'grid grid-cols-4 gap-3 text-center' }}">
                            @foreach(($page['countdown']['items'] ?? []) as $item)
                                <div class="{{ $heroCountdown['item'] ?? 'min-w-12' }}">
                                    <p
                                        class="{{ $heroCountdown['value'] ?? 'text-xl font-bold tabular-nums' }}"
                                        x-text="{{ $item['method'] ?? 'days' }}().toString().padStart(2, '0')"
                                    ></p>

                                    <p class="{{ $heroCountdown['unit'] ?? 'mt-1 text-[0.65rem] uppercase' }}">
                                        {{ $item['label'] ?? '' }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
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
            </div>
        @endif
        <x-webinars.floating-card
            :content="$page"
            :style="$style"
            :target="$countdownTarget"
        />

        <x-webinars.registration-form-modal
            :page="$page"
            :tokens="$tokens"
            :series="$series"
        />

    </section>
</x-layouts.public>