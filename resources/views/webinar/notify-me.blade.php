@php
    $style = array_replace_recursive(
        config('webinars.style', []),
        config('webinars.notify-me.style', []),
    );

    $page = array_replace_recursive(
        config('webinars.content', []),
        config('webinars.notify-me.content', []),
        $series->meta['public_page'] ?? [],
    );

    $tokens = $style['tokens'] ?? [];
@endphp

<x-layouts.public
    :title="$page['title'] ?? 'Get Webinar Updates'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section class="{{ $style['section'] ?? 'bg-white' }}">
        <div class="{{ $style['hero']['section'] ?? 'bg-secondary text-white' }}">
            <div class="{{ $style['hero']['inner'] ?? 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-16 sm:py-24 lg:grid-cols-[1.05fr_0.95fr] lg:items-center' }}">
                <div class="{{ $style['hero']['wrapper'] ?? 'max-w-4xl text-left' }}">
                    @if(filled($page['hero']['eyebrow'] ?? null))
                        <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em]' }}">
                            {{ $page['hero']['eyebrow'] }}
                        </p>
                    @endif

                    <h1 class="{{ $style['hero']['title'] ?? ($tokens['hero_title'] ?? 'mt-5 text-4xl font-extrabold tracking-tight sm:text-6xl') }}">
                        {{ $page['hero']['title_prefix'] ?? '' }}

                        <span>{{ $series->title }}</span>
                    </h1>

                    @if(filled($page['hero']['body'] ?? null))
                        <p class="{{ $style['hero']['body'] ?? 'mt-6 max-w-2xl text-lg sm:text-xl' }}">
                            {{ $page['hero']['body'] }}
                        </p>
                    @endif

                    @if(filled($page['hero']['supporting_copy'] ?? []))
                        <div class="{{ $style['hero']['supporting_copy_wrapper'] ?? 'mt-5 space-y-2' }}">
                            @foreach($page['hero']['supporting_copy'] as $line)
                                <p class="{{ $style['hero']['supporting_copy'] ?? 'max-w-xl text-white/80' }}">
                                    {{ $line }}
                                </p>
                            @endforeach
                        </div>
                    @endif

                    @if(filled($page['hero']['bullets']['list'] ?? []))
                        <div class="{{ $style['hero']['bullets_wrapper'] ?? 'mt-8' }}">
                            @if(filled($page['hero']['bullets']['intro'] ?? null))
                                <p class="{{ $style['hero']['bullets_intro'] ?? 'text-lg font-extrabold text-primary' }}">
                                    {{ $page['hero']['bullets']['intro'] }}
                                </p>
                            @endif

                            <ul class="{{ $style['hero']['bullets_list'] ?? 'mt-4 grid gap-3' }}">
                                @foreach($page['hero']['bullets']['list'] as $bullet)
                                    <li class="{{ $style['hero']['bullet_item'] ?? 'flex gap-3 text-base font-bold leading-6 text-white' }}">
                                        <span class="{{ $style['hero']['bullet_icon'] ?? 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white' }}">✓</span>
                                        <span>{{ $bullet }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                @if($page['form_card']['enabled'] ?? true)
                    <div class="{{ $style['form_card']['class'] ?? 'rounded-3xl border border-black/10 bg-white p-6 text-ink shadow-2xl shadow-black/20 sm:p-8' }}">
                        <h2 class="{{ $style['form_card']['title'] ?? 'text-2xl font-extrabold tracking-[-0.03em] text-ink' }}">
                            {{ $page['form_card']['title'] ?? 'Get Notified' }}
                        </h2>

                        @if(filled($page['form_card']['body'] ?? null))
                            <p class="{{ $style['form_card']['body'] ?? 'mt-2 text-sm font-medium leading-6 text-slate-600' }}">
                                {{ $page['form_card']['body'] }}
                            </p>
                        @endif

                        <form
                            method="POST"
                            action="{{ route($page['form']['route'] ?? 'webinar.notify-me.store', $series->slug) }}"
                            class="{{ $style['form']['class'] ?? 'mt-6 space-y-5' }}"
                        >
                            @csrf

                            <div class="{{ $style['form']['grid'] ?? 'grid gap-4 sm:grid-cols-2' }}">
                                <div>
                                    <label for="first_name" class="{{ $style['form']['label'] ?? 'text-sm font-extrabold text-ink' }}">
                                        {{ $page['fields']['first_name']['label'] ?? 'First Name' }}
                                    </label>

                                    <input
                                        id="first_name"
                                        name="first_name"
                                        type="text"
                                        value="{{ old('first_name') }}"
                                        placeholder="{{ $page['fields']['first_name']['placeholder'] ?? 'Enter your first name' }}"
                                        required
                                        class="{{ $style['form']['input'] ?? 'mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20' }}"
                                    >

                                    @error('first_name')
                                        <p class="{{ $style['form']['error'] ?? 'mt-2 text-sm font-bold text-red-600' }}">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="last_name" class="{{ $style['form']['label'] ?? 'text-sm font-extrabold text-ink' }}">
                                        {{ $page['fields']['last_name']['label'] ?? 'Last Name' }}
                                    </label>

                                    <input
                                        id="last_name"
                                        name="last_name"
                                        type="text"
                                        value="{{ old('last_name') }}"
                                        placeholder="{{ $page['fields']['last_name']['placeholder'] ?? 'Enter your last name' }}"
                                        required
                                        class="{{ $style['form']['input'] ?? 'mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20' }}"
                                    >

                                    @error('last_name')
                                        <p class="{{ $style['form']['error'] ?? 'mt-2 text-sm font-bold text-red-600' }}">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <label for="email" class="{{ $style['form']['label'] ?? 'text-sm font-extrabold text-ink' }}">
                                    {{ $page['fields']['email']['label'] ?? 'Email Address' }}
                                </label>

                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    value="{{ old('email') }}"
                                    placeholder="{{ $page['fields']['email']['placeholder'] ?? 'Enter your email address' }}"
                                    required
                                    class="{{ $style['form']['input'] ?? 'mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20' }}"
                                >

                                @error('email')
                                    <p class="{{ $style['form']['error'] ?? 'mt-2 text-sm font-bold text-red-600' }}">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="phone" class="{{ $style['form']['label'] ?? 'text-sm font-extrabold text-ink' }}">
                                    {{ $page['fields']['phone']['label'] ?? 'Phone Number' }}
                                </label>

                                <input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    value="{{ old('phone') }}"
                                    placeholder="{{ $page['fields']['phone']['placeholder'] ?? 'Enter your phone number' }}"
                                    class="{{ $style['form']['input'] ?? 'mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20' }}"
                                >

                                @error('phone')
                                    <p class="{{ $style['form']['error'] ?? 'mt-2 text-sm font-bold text-red-600' }}">{{ $message }}</p>
                                @enderror
                            </div>

                            <label class="{{ $style['form']['checkbox_row'] ?? 'flex gap-3 rounded-2xl bg-soft p-4 text-sm font-medium leading-6 text-ink' }}">
                                <input
                                    type="checkbox"
                                    name="consent_messages"
                                    value="1"
                                    required
                                    @checked(old('consent_messages'))
                                    class="{{ $style['form']['checkbox'] ?? 'mt-1 h-4 w-4 rounded border-black/20 text-primary focus:ring-primary' }}"
                                >

                                <span>{{ $page['fields']['consent_messages']['label'] ?? 'I agree to receive automated email and text messages related to webinar scheduling updates and follow-up communications. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help. Consent is not a condition of purchase.' }}</span>
                            </label>

                            @error('consent_messages')
                                <p class="{{ $style['form']['error'] ?? 'mt-2 text-sm font-bold text-red-600' }}">{{ $message }}</p>
                            @enderror

                            <x-ui.button
                                type="submit"
                                class="{{ $tokens['primary_button'] ?? 'w-full' }}"
                            >
                                {{ $page['submit']['label'] ?? 'Notify Me When Scheduled' }}
                            </x-ui.button>

                            @if(filled($page['form_card']['helper_text'] ?? null))
                                <p class="{{ $style['form_card']['helper_text'] ?? 'text-center text-xs font-bold text-slate-500' }}">
                                    {{ $page['form_card']['helper_text'] }}
                                </p>
                            @endif
                        </form>
                    </div>
                @endif
            </div>
        </div>

        @if($page['compliance']['enabled'] ?? false)
            <div class="{{ $style['compliance']['wrapper'] ?? 'bg-secondary px-6 pb-10 text-center' }}">
                <p class="{{ $style['compliance']['text'] ?? ($tokens['muted'] ?? 'text-sm text-slate-500') }}">
                    {{ $page['compliance']['text'] ?? '' }}
                </p>
            </div>
        @endif
    </section>
</x-layouts.public>