@php
    $page = array_replace_recursive(
        config('theme.webinar_public.pages.index', []),
        config('theme.webinar_public.pages.register', []),
        $series->meta['public_page'] ?? []
    );

    $tokens = config('theme.webinar_public.tokens', []);
    $webinar = $series->nextUpcomingWebinar();
    $secondaryCtaRoute = $page['secondary_cta']['route'] ?? 'webinar.show';
    $secondaryCtaHref = route($secondaryCtaRoute, $series->slug);

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
        x-data="{ formOpen: {{ $errors->any() ? 'true' : 'false' }} }"
        @keydown.escape.window="formOpen = false"
        class="{{ $page['section'] ?? 'mx-auto w-full max-w-6xl px-6 py-16 sm:py-24' }}"
    >
        @if($page['hero']['enabled'] ?? true)
            <div class="{{ $page['hero']['wrapper'] ?? 'mx-auto max-w-4xl' }} {{ $page['hero']['align'] ?? 'text-center' }}">
                @if(filled($page['hero']['eyebrow'] ?? null))
                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                        {{ $page['hero']['eyebrow'] }}
                    </p>
                @endif

                <h1 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }} mt-4">
                    {{ $page['hero']['title'] ?? (($page['hero']['title_prefix'] ?? 'Register for') . ' ' . $series->title) }}
                </h1>

                @if(filled($page['hero']['body'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-6">
                        {{ $page['hero']['body'] }}
                    </p>
                @endif

                @if(filled($page['hero']['supporting_copy'] ?? null))
                    <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }} mt-4">
                        {{ $page['hero']['supporting_copy'] }}
                    </p>
                @endif
            </div>
        @endif

        @if($page['urgency_stats']['enabled'] ?? false)
            <div class="{{ $page['urgency_stats']['wrapper'] ?? 'mt-6 space-y-1 text-center' }}">
                @foreach(($page['urgency_stats']['items'] ?? []) as $item)
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }}">
                        <span class="font-bold text-white">{{ $item['value'] ?? '' }}</span>
                        <span>{{ $item['label'] ?? '' }}</span>
                    </p>
                @endforeach

                @if(filled($page['urgency_stats']['closing_line'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-4">
                        {{ $page['urgency_stats']['closing_line'] }}
                    </p>
                @endif
            </div>
        @endif

        @if($page['primary_cta']['enabled'] ?? false)
            <div class="{{ $page['primary_cta']['wrapper'] ?? 'mt-10 flex flex-col items-center gap-4 text-center' }}">
                @if(filled($page['primary_cta']['pretext'] ?? null))
                    <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                        {{ $page['primary_cta']['pretext'] }}
                    </p>
                @endif

                <x-ui.button
                    type="button"
                    @click="formOpen = true"
                >
                    {{ $page['primary_cta']['label'] ?? 'Save My Seat' }}
                </x-ui.button>

                @if(filled($page['primary_cta']['helper_text'] ?? null))
                    <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                        {{ $page['primary_cta']['helper_text'] }}
                    </p>
                @endif
            </div>
        @endif

        @if(($page['event_details']['enabled'] ?? false) && $eventDetailsItems->isNotEmpty())
            <div class="{{ $page['event_details']['wrapper'] ?? 'mt-16' }}">
                @if(filled($page['event_details']['heading'] ?? null))
                    <div class="{{ $page['event_details']['heading_class'] ?? 'text-center' }}">
                        <h2 class="{{ $tokens['section_title'] ?? 'text-3xl font-bold tracking-tight' }}">
                            {{ $page['event_details']['heading'] }}
                        </h2>
                    </div>
                @endif

                <div class="{{ $page['event_details']['grid'] ?? 'mt-8 grid gap-4 md:grid-cols-3' }}">
                    @foreach($eventDetailsItems as $item)
                        <div class="{{ $page['event_details']['card'] ?? 'rounded-2xl border bg-white p-6 shadow-sm' }}">
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">
                                {{ $item['label'] ?? '' }}
                            </p>

                            <p class="mt-3 text-xl font-bold tracking-tight text-slate-900">
                                {{ $item['resolved_value'] }}
                            </p>
                        </div>
                    @endforeach
                </div>

                @if(filled($page['event_details']['footnote'] ?? null))
                    <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }} mt-6 text-center">
                        {{ $page['event_details']['footnote'] }}
                    </p>
                @endif
            </div>
        @endif

        <div class="bg-green-400">TEST</div>

        @if($page['instructor']['enabled'] ?? false)
            <div class="{{ $page['instructor']['wrapper'] ?? 'mt-20 grid gap-10 lg:grid-cols-[0.95fr_1.05fr] lg:items-center' }}">
                @if(filled($page['instructor']['image'] ?? null))
                    <div class="{{ $page['instructor']['image_wrapper'] ?? 'mx-auto max-w-md' }}">
                        <img
                            src="{{ $page['instructor']['image'] }}"
                            alt="{{ $page['instructor']['image_alt'] ?? 'Instructor' }}"
                            class="w-full rounded-3xl object-cover shadow-2xl shadow-slate-950/30"
                        >
                    </div>
                @endif

                <div class="space-y-4">
                    @if(filled($page['instructor']['eyebrow'] ?? null))
                        <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                            {{ $page['instructor']['eyebrow'] }}
                        </p>
                    @endif

                    @if(filled($page['instructor']['heading'] ?? null))
                        <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }}">
                            {{ $page['instructor']['heading'] }}
                        </h2>
                    @endif

                    @foreach(($page['instructor']['body'] ?? []) as $paragraph)
                        <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }}">
                            {{ $paragraph }}
                        </p>
                    @endforeach
                </div>
            </div>
        @endif

        @if($page['secondary_cta']['enabled'] ?? false)
            <div class="{{ $page['secondary_cta']['wrapper'] ?? 'mt-20 text-center' }}">
                @if(filled($page['secondary_cta']['headline'] ?? null))
                    <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }}">
                        {{ $page['secondary_cta']['headline'] }}
                    </h2>
                @endif

                <div class="mt-6 flex flex-col items-center gap-4">
                    <x-ui.button
                        type="button"
                        @click="formOpen = true"
                    >
                        {{ $page['secondary_cta']['label'] ?? 'Reserve Your Spot Now' }}
                    </x-ui.button>

                    @if(filled($page['secondary_cta']['helper_text'] ?? null))
                        <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                            {{ $page['secondary_cta']['helper_text'] }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        @if($page['trust']['enabled'] ?? false)
            <div class="{{ $page['trust']['wrapper'] ?? 'mt-16 text-center' }}">
                @if(filled($page['trust']['headline'] ?? null))
                    <h2 class="{{ $tokens['hero_title'] ?? 'text-4xl font-bold tracking-tight sm:text-5xl' }}">
                        {{ $page['trust']['headline'] }}
                    </h2>
                @endif

                @if(filled($page['trust']['body'] ?? null))
                    <p class="{{ $tokens['body'] ?? 'text-lg leading-8 text-slate-600' }} mt-6">
                        {{ $page['trust']['body'] }}
                    </p>
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
        @endif

        @if($page['compliance']['enabled'] ?? false)
            <div class="{{ $page['compliance']['wrapper'] ?? 'mt-16 text-center' }}">
                <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                    {{ $page['compliance']['text'] ?? '' }}
                </p>
            </div>
        @endif

        <div
            x-cloak
            x-show="formOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-105"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-105"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
            aria-labelledby="register-modal-title"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="absolute inset-0 bg-black/70"
                @click="formOpen = false"
            ></div>

            <div
                class="relative z-10 w-full max-w-2xl"
                @click.stop
            >
                <x-ui.card class="{{ $page['form_card']['class'] ?? '' }}">
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div class="space-y-2">
                            @if(filled($page['form_card']['title'] ?? null))
                                <h2
                                    id="register-modal-title"
                                    class="text-2xl font-bold tracking-tight text-slate-900"
                                >
                                    {{ $page['form_card']['title'] }}
                                </h2>
                            @endif

                            @if(filled($page['form_card']['body'] ?? null))
                                <p class="{{ $tokens['muted'] ?? 'text-sm text-slate-500' }}">
                                    {{ $page['form_card']['body'] }}
                                </p>
                            @endif
                        </div>

                        <button
                            type="button"
                            @click="formOpen = false"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                            aria-label="Close registration form"
                        >
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('webinar.store', $series->slug) }}"
                        class="{{ $tokens['form_grid'] ?? 'space-y-4' }}"
                    >
                        @csrf

                        <div>
                            <x-ui.form.label for="first_name">
                                {{ $page['fields']['first_name']['label'] ?? 'First Name' }}
                            </x-ui.form.label>
                            <x-ui.form.input
                                id="first_name"
                                name="first_name"
                                :value="old('first_name')"
                                :placeholder="$page['fields']['first_name']['placeholder'] ?? 'First name'"
                            />
                            @error('first_name') <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <x-ui.form.label for="last_name">
                                {{ $page['fields']['last_name']['label'] ?? 'Last Name' }}
                            </x-ui.form.label>
                            <x-ui.form.input
                                id="last_name"
                                name="last_name"
                                :value="old('last_name')"
                                :placeholder="$page['fields']['last_name']['placeholder'] ?? 'Last name'"
                            />
                            @error('last_name') <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <x-ui.form.label for="email">
                                {{ $page['fields']['email']['label'] ?? 'Email Address' }}
                            </x-ui.form.label>
                            <x-ui.form.input
                                id="email"
                                name="email"
                                type="email"
                                :value="old('email')"
                                :placeholder="$page['fields']['email']['placeholder'] ?? 'Email address'"
                            />
                            @error('email') <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <x-ui.form.label for="phone">
                                {{ $page['fields']['phone']['label'] ?? 'Phone Number' }}
                            </x-ui.form.label>
                            <x-ui.form.input
                                id="phone"
                                name="phone"
                                :value="old('phone')"
                                :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                            />
                            @error('phone') <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p> @enderror
                        </div>

                        @php
                            $checkbox = config('theme.webinar_public.components.checkbox', []);
                        @endphp

                        <div>
                            <label
                                for="consent_messages"
                                class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                            >
                                <input
                                    id="consent_messages"
                                    name="consent_messages"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('consent_messages'))
                                    class="{{ $checkbox['input'] ?? 'mt-1 h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                >

                                <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                    {{ $page['fields']['consent_messages']['label'] }}
                                </span>
                            </label>

                            @error('consent_messages')
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <x-ui.button type="submit" class="{{ $tokens['primary_button'] ?? 'w-full' }}">
                            {{ $page['submit']['label'] ?? 'Reserve My Spot' }}
                        </x-ui.button>
                    </form>
                </x-ui.card>
            </div>
        </div>
    </section>
</x-layouts.public>