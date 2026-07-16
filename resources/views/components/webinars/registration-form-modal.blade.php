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
        is_array($style['components']['checkbox'] ?? null)
            ? $style['components']['checkbox']
            : [],
    );

    $notificationSection = $page['sections']['notifications'] ?? [];
    $marketingSection = $page['sections']['marketing'] ?? [];

    $transactionalChannels = $webinarRegistrationChannels['transactional'] ?? ['email'];
    $marketingChannels = $webinarRegistrationChannels['marketing'] ?? ['email'];

    $transactionalEmailAvailable = in_array('email', $transactionalChannels, true);
    $transactionalSmsAvailable = in_array('sms', $transactionalChannels, true);
    $marketingEmailAvailable = in_array('email', $marketingChannels, true);
    $marketingSmsAvailable = in_array('sms', $marketingChannels, true);
    $smsAvailable = $transactionalSmsAvailable || $marketingSmsAvailable;

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
    x-ref="registrationModal"
    @keydown="trapRegistrationModalFocus($event)"
    @keydown.escape.window="formOpen && closeRegistrationModal()"
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
        @click="closeRegistrationModal()"
    ></div>

    <div
        class="relative z-10 mx-auto flex min-h-full w-full max-w-2xl items-start sm:items-center"
        @click.stop
    >
        <x-ui.card class="{{ $style['form_card']['class'] ?? '' }} max-h-[calc(100dvh-1.5rem)] w-full overflow-y-auto sm:max-h-[calc(100dvh-3rem)]">
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
                    x-ref="registrationModalClose"
                    @click="closeRegistrationModal()"
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
                            required
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
                            required
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
                            :aria-describedby="$smsAvailable ? 'phone_sms_helper' : null"
                            x-bind:required="transactionalSmsConsent || marketingSmsConsent"
                            x-bind:aria-required="transactionalSmsConsent || marketingSmsConsent ? 'true' : 'false'"
                            :value="old('phone', $registrationPrefill['phone'] ?? null)"
                            :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                        />

                        @if($smsAvailable)
                            <p id="phone_sms_helper" class="mt-1 text-xs font-medium leading-5 text-slate-500">
                                {{ $page['fields']['phone']['helper'] ?? 'Required to receive SMS.' }}
                            </p>
                        @endif

                        @error('phone')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                @if($page['consent_header']['enabled'] ?? true)
                    <div class="{{ $style['consent_header']['wrapper'] ?? 'rounded-2xl border border-primary/20 bg-primary/5 p-4' }}">
                        @if(filled($page['consent_header']['body'] ?? null))
                            <p class="{{ $style['consent_header']['body'] ?? 'mt-1 text-sm font-medium text-slate-600' }}">
                                {{ $page['consent_header']['body'] }}
                            </p>
                        @endif
                    </div>
                @endif

                <fieldset class="mx-2 rounded-2xl bg-slate-50 pt-2 pb-4 px-4 border border-slate-300">
                    <legend class="text-base font-semibold text-slate-900">
                        {{ $notificationSection['title'] ?? 'Webinar Registration (Required)' }}
                    </legend>

                    @if(filled($notificationSection['body'] ?? null))
                        <p class="text-xs text-slate-600">
                            {{ $notificationSection['body'] }}
                        </p>
                    @endif

                    <div class="mt-3 space-y-4">
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
                                        @if(filled($page['fields']['consent_messages']['email']['helper'] ?? null))
                                            aria-describedby="transactional_email_consent_helper"
                                        @endif
                                        class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                    >

                                    <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                        {{ $page['fields']['consent_messages']['email']['label'] ?? 'Email me webinar information.' }}
                                    </span>
                                </label>

                                @if(filled($page['fields']['consent_messages']['email']['helper'] ?? null))
                                    <p
                                        id="transactional_email_consent_helper"
                                        class="pl-7 text-xs leading-5 text-slate-500"
                                    >
                                        {{ $page['fields']['consent_messages']['email']['helper'] }}
                                    </p>
                                @endif

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

                                    <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                        {{ $page['fields']['consent_messages']['sms']['label'] ?? 'Text me webinar reminders and access information. (Optional)' }}
                                    </span>
                                </label>

                                @if(filled($page['fields']['consent_messages']['sms']['disclosure'] ?? null))
                                    <p
                                        id="transactional_sms_consent_disclosure"
                                        class="pl-7 text-xs leading-5 text-slate-500"
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
                    </div>

                    @error('transactional_consent')
                        <p class="{{ $tokens['field_error'] ?? 'mt-3 text-sm text-red-600' }}">
                            {{ $message }}
                        </p>
                    @enderror
                </fieldset>

                {{-- <fieldset class="mx-2 rounded-2xl border border-slate-200 py-2 px-4">
                    <legend class="px-1 text-base font-semibold text-slate-900">
                        {{ $marketingSection['title'] ?? 'Stay Connected (Optional)' }}
                    </legend>

                    @if(filled($marketingSection['body'] ?? null))
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $marketingSection['body'] }}
                        </p>
                    @endif

                    <div class="mt-1 space-y-4">
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

                                    <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                        {{ $page['fields']['marketing_consent_messages']['email']['label'] ?? 'Send me future webinar and educational emails.' }}
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

                                    <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                        {{ $page['fields']['marketing_consent_messages']['sms']['label'] ?? 'Send me future webinar and educational texts.' }}
                                    </span>
                                </label>

                                @error('marketing_sms_consent')
                                    <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                        {{ $message }}
                                    </p>
                                @enderror
                            
                                @if(filled($page['fields']['marketing_consent_messages']['sms']['disclosure'] ?? null))
                                    <p
                                        id="marketing_sms_consent_disclosure"
                                        class="pl-7 text-xs leading-5 text-slate-500"
                                    >
                                        {{ $page['fields']['marketing_consent_messages']['sms']['disclosure'] }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                </fieldset> --}}

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
