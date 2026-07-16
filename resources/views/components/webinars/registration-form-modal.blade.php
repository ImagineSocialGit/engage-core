@props([
    'page',
    'tokens',
    'style' => [],
    'series',
    'webinarRegistrationChannels' => [],
    'registrationPrefill' => [],
])

@php
    $checkbox = array_replace_recursive(
        config('webinars.style.components.checkbox', []),
        config('webinars.register.style.components.checkbox', []),
    );

    $notificationSection = $page['sections']['notifications'] ?? [];
    $marketingSection = $page['sections']['marketing'] ?? [];

    $transactionalChannels = $webinarRegistrationChannels['transactional'] ?? ['email'];
    $marketingChannels = $webinarRegistrationChannels['marketing'] ?? ['email'];

    $transactionalEmailAvailable = in_array('email', $transactionalChannels, true);
    $transactionalSmsAvailable = in_array('sms', $transactionalChannels, true);
    $marketingEmailAvailable = in_array('email', $marketingChannels, true);
    $marketingSmsAvailable = in_array('sms', $marketingChannels, true);

    $legalLinks = collect($page['legal_links']['links'] ?? [])
        ->filter(function (mixed $link): bool {
            if (! is_array($link)) {
                return false;
            }

            $label = $link['label'] ?? null;
            $url = $link['url'] ?? null;

            return is_string($label)
                && trim($label) !== ''
                && is_string($url)
                && trim($url) !== ''
                && trim($url) !== '#';
        })
        ->values();
@endphp

<div
    x-cloak
    x-show="formOpen"
    x-trap.noscroll="formOpen"
    x-init="$watch('formOpen', value => value && $nextTick(() => $refs.firstName?.focus()))"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-105"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-105"
    class="fixed inset-0 z-50 overflow-y-auto p-3 sm:p-6"
    aria-labelledby="register-modal-title"
    aria-modal="true"
    role="dialog"
>
    <div
        class="fixed inset-0 bg-black/70"
        @click="formOpen = false"
    ></div>

    <div
        class="relative z-10 mx-auto flex min-h-full w-full max-w-2xl items-start sm:items-center"
        @click.stop
    >
        <x-ui.card class="{{ $page['form_card']['class'] ?? '' }} max-h-[calc(100dvh-1.5rem)] w-full overflow-y-auto sm:max-h-[calc(100dvh-3rem)]">
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
                        <p class="{{ $tokens['muted_dark'] ?? 'text-sm text-slate-500' }}">
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
                action="{{ route('webinar.registration.store', $series->slug) }}"
                class="{{ $tokens['form_grid'] ?? 'space-y-4' }}"
            >
                @csrf

                
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.form.label for="first_name">
                            {{ $page['fields']['first_name']['label'] ?? 'First Name' }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="first_name"
                            name="first_name"
                            x-ref="firstName"
                            :value="old('first_name', $registrationPrefill['first_name'] ?? null)"
                            :placeholder="$page['fields']['first_name']['placeholder'] ?? 'First name'"
                        />

                        @error('first_name')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="last_name">
                            {{ $page['fields']['last_name']['label'] ?? 'Last Name' }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="last_name"
                            name="last_name"
                            :value="old('last_name', $registrationPrefill['last_name'] ?? null)"
                            :placeholder="$page['fields']['last_name']['placeholder'] ?? 'Last name'"
                        />

                        @error('last_name')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.form.label for="email">
                            {{ $page['fields']['email']['label'] ?? 'Email Address' }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="email"
                            name="email"
                            type="email"
                            :value="old('email', $registrationPrefill['email'] ?? null)"
                            :placeholder="$page['fields']['email']['placeholder'] ?? 'Email address'"
                        />

                        @error('email')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="phone">
                            {{ $page['fields']['phone']['label'] ?? 'Mobile phone' }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="phone"
                            name="phone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            aria-describedby="phone_sms_helper"
                            x-bind:required="transactionalSmsConsent || marketingSmsConsent"
                            :value="old('phone', $registrationPrefill['phone'] ?? null)"
                            :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                        />

                        <p id="phone_sms_helper" class="mt-1 text-xs leading-5 text-slate-500">
                            {{ $page['fields']['phone']['helper'] ?? 'Required only when you choose an SMS option.' }}
                        </p>

                        @error('phone')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-3 rounded-2xl bg-slate-50 p-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">
                            {{ $notificationSection['title'] ?? 'Webinar Updates' }}
                        </h3>

                        @if(filled($notificationSection['body'] ?? null))
                            <p class="mt-1 text-sm text-slate-600">
                                {{ $notificationSection['body'] }}
                            </p>
                        @endif
                    </div>

                    @if($transactionalEmailAvailable)
                        <div>
                            <label
                                for="transactional_email_consent"
                                class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                            >
                                <input
                                    id="transactional_email_consent"
                                    name="transactional_email_consent"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('transactional_email_consent'))
                                    class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                >

                                <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                    {{ $page['fields']['consent_messages']['email']['label'] ?? 'Webinar email' }}
                                </span>
                            </label>

                            @error('transactional_email_consent')
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endif

                    @if($transactionalSmsAvailable)
                        <div>
                            <label
                                for="transactional_sms_consent"
                                class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                            >
                                <input
                                    id="transactional_sms_consent"
                                    name="transactional_sms_consent"
                                    type="checkbox"
                                    value="1"
                                    x-model="transactionalSmsConsent"
                                    @checked(old('transactional_sms_consent'))
                                    @if(filled($page['fields']['consent_messages']['sms']['disclosure'] ?? null))
                                        aria-describedby="transactional_sms_consent_disclosure"
                                    @endif
                                    class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                >

                                <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                    {{ $page['fields']['consent_messages']['sms']['label'] ?? 'Webinar SMS' }}
                                </span>
                            </label>

                            @if(filled($page['fields']['consent_messages']['sms']['disclosure'] ?? null))
                                <p
                                    id="transactional_sms_consent_disclosure"
                                    class="mt-2 pl-7 text-xs leading-5 text-slate-500"
                                >
                                    {{ $page['fields']['consent_messages']['sms']['disclosure'] }}
                                </p>
                            @endif

                            @error('transactional_sms_consent')
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endif

                    @error('transactional_consent')
                        <p class="{{ $tokens['field_error'] ?? 'text-sm text-red-600' }}">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="space-y-3 rounded-2xl border border-slate-200 p-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">
                            {{ $marketingSection['title'] ?? 'Keep Learning — Optional' }}
                        </h3>

                        @if(filled($marketingSection['body'] ?? null))
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $marketingSection['body'] }}
                            </p>
                        @endif
                    </div>

                    @if($marketingEmailAvailable)
                        <div>
                            <label
                                for="marketing_email_consent"
                                class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                            >
                                <input
                                    id="marketing_email_consent"
                                    name="marketing_email_consent"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('marketing_email_consent'))
                                    class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                >

                                <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                    {{ $page['fields']['marketing_consent_messages']['email']['label'] ?? 'Marketing email' }}
                                </span>
                            </label>

                            @error('marketing_email_consent')
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endif

                    @if($marketingSmsAvailable)
                        <div>
                            <label
                                for="marketing_sms_consent"
                                class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                            >
                                <input
                                    id="marketing_sms_consent"
                                    name="marketing_sms_consent"
                                    type="checkbox"
                                    value="1"
                                    x-model="marketingSmsConsent"
                                    @checked(old('marketing_sms_consent'))
                                    @if(filled($page['fields']['marketing_consent_messages']['sms']['disclosure'] ?? null))
                                        aria-describedby="marketing_sms_consent_disclosure"
                                    @endif
                                    class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                >

                                <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                    {{ $page['fields']['marketing_consent_messages']['sms']['label'] ?? 'Marketing SMS' }}
                                </span>
                            </label>

                            @if(filled($page['fields']['marketing_consent_messages']['sms']['disclosure'] ?? null))
                                <p
                                    id="marketing_sms_consent_disclosure"
                                    class="mt-2 pl-7 text-xs leading-5 text-slate-500"
                                >
                                    {{ $page['fields']['marketing_consent_messages']['sms']['disclosure'] }}
                                </p>
                            @endif

                            @error('marketing_sms_consent')
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endif
                </div>

                @if(($page['legal_links']['enabled'] ?? false) && $legalLinks->isNotEmpty())
                    <p class="{{ $style['legal_links']['wrapper'] ?? 'text-xs leading-5 text-slate-600' }}">
                        {{ $page['legal_links']['intro'] ?? 'By registering, you agree to our' }}
                        @foreach($legalLinks as $link)
                            @if(! $loop->first)
                                {{ $loop->last ? ' and ' : ', ' }}
                            @endif
                            <a
                                href="{{ trim($link['url']) }}"
                                target="_blank"
                                rel="noopener"
                                class="{{ $style['legal_links']['link'] ?? 'font-semibold underline' }}"
                            >{{ trim($link['label']) }}</a>@if($loop->last).@endif
                        @endforeach
                    </p>
                @endif

                <x-ui.button type="submit" class="{{ $tokens['primary_button'] ?? 'w-full' }}">
                    {{ $page['submit']['label'] ?? 'Reserve My Spot' }}
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
