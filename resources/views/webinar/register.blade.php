@php
    $tokens = $style['tokens'] ?? [];
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

    $heroTheme = $style['hero']['theme'] ?? 'dark';
    $heroCountdown = $style['countdown']['themes'][$heroTheme] ?? $style['countdown']['themes']['dark'];
@endphp

<x-layouts.public
    :title="$page['title'] ?? 'Register for Webinar'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section
        x-data="webinarRegistrationPage(@js([
            'countdownTarget' => $countdownTarget,
            'formOpen' => $errors->any(),
        ]))"
        class="{{ $style['section'] ?? 'bg-white' }}"
        >

        <x-webinars.hero
            :page="$page"
            :style="$style"
            :tokens="$tokens"
            :series="$series"
            :event-details-items="$eventDetailsItems"
            :countdown-target="$countdownTarget"
            :hero-countdown="$heroCountdown"
        />

        <div>

            @if(($page['problem']['enabled'] ?? false) || ($page['instructor']['enabled'] ?? false))
            
                <div class="{{ $style['problem']['section'] ?? 'bg-white text-ink' }}">
                    <div class="{{ $style['problem']['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-12 px-6 py-16 sm:py-24 lg:grid-cols-2 lg:items-center' }}">
                        @if($page['problem']['enabled'] ?? false)
                            <div class="{{ $style['problem']['content_wrapper'] ?? 'space-y-6' }}">
                                @if(filled($page['problem']['eyebrow'] ?? null))
                                    <p class="{{ $style['problem']['eyebrow'] ?? $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
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
                                    <p class="{{ $style['problem']['bullets_intro'] ?? 'text-2xl font-semibold' }}">
                                        {{ $page['problem']['bullets']['intro'] }}
                                    </p>
                                    <ul class="{{ $style['problem']['list'] ?? 'space-y-3' }}">
                                        @foreach($page['problem']['bullets']['list'] as $bullet)
                                            <li class="{{ $style['problem']['list_item'] ?? 'flex gap-3 text-base font-bold' }}">
                                                <span class="{{ $style['problem']['icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                                                <span>{{ $bullet }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @foreach(($page['problem']['post_body'] ?? []) as $paragraph)
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
                                        <p class="{{ $style['instructor']['eyebrow'] ?? $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                                            {{ $page['instructor']['eyebrow'] }}
                                        </p>
                                    @endif

                                    @if(filled($page['instructor']['heading'] ?? null))
                                        <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                                            {{ $page['instructor']['heading'] }}
                                        </h2>
                                        @if(filled($page['instructor']['subheading'] ?? null))
                                            <h3 class="{{ $style['instructor']['eyebrow'] ?? $tokens['eyebrow'] ?? 'text-3xl font-bold tracking-tight' }}">
                                                {{ $page['instructor']['subheading'] }}
                                            </h3>
                                        @endif
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
                <div class="{{ $style['final_close']['wrapper'] ?? 'bg-secondary px-6 pb-20 text-white sm:pb-28' }}">
                    <div class="{{ $style['final_close']['inner'] ?? 'mx-auto max-w-4xl text-center' }}">
                        @if(filled($page['final_close']['headline'] ?? null))
                            <h2 class="{{ $style['final_close']['headline'] ?? ($tokens['dark_section_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl') }}">
                                {{ $page['final_close']['headline'] }}
                            </h2>
                        @endif

                        @if(filled($page['final_close']['bullets'] ?? []))
                        <div class="flex flex-col max-w-md mx-auto">
                            <p class="{{ $style['final_close']['bullets_intro'] ?? 'text-2xl font-semibold text-primary mt-6 mb-2' }}">
                                {{ $page['final_close']['bullets']['intro'] }}
                            </p>
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
                            <p class="{{ $style['final_close']['body'] ?? 'mt-8 text-lg leading-8 text-ink' }}">
                                {{ $page['final_close']['body'] }}
                            </p>
                        @endif

                        @if(filled($page['final_close']['closing_copy'] ?? null))
                            <p class="{{ $style['final_close']['closing_copy'] ?? 'text-lg leading-8 text-ink' }}">
                                {{ $page['final_close']['closing_copy'] }}
                            </p>
                        @endif

                        @if(($page['final_close']['countdown']['enabled'] ?? false) && filled($countdownTarget))
                            <x-webinars.countdown
                                :content="$page"
                                :countdown="$page['final_close']['countdown'] ?? []"
                                :style="$style"
                                :target="$countdownTarget"
                                :theme="$finalCloseTheme"
                            />
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
            
        </div>

        @if($page['primary_cta']['enabled'] ?? false)
            <div class="{{ $style['mobile_after_hero_cta']['wrapper'] ?? 'lg:hidden flex flex-col gap-3 bg-secondary px-4 py-4' }}">
                @if(filled($page['primary_cta']['pretext'] ?? null))
                    <p class="{{ $style['primary_cta']['pretext'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                        {{ $page['primary_cta']['pretext'] }}
                    </p>
                @endif

                @if(($page['countdown']['enabled'] ?? false) && filled($countdownTarget))
                    <x-webinars.countdown
                        :content="$page"
                        :style="$style"
                        :target="$countdownTarget"
                        :theme="$heroTheme"
                    />
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
            :webinar-registration-channels="$webinarRegistrationChannels ?? []"
            :registration-prefill="$registrationPrefill ?? []"
        />

    </section>
</x-layouts.public>