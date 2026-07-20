
@props([
    'page',
    'tokens',
    'style' => [],
    'series',
    'webinar',
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

    $transactionalEmailConfigured = data_get($page, 'consents.transactional.email', true) === true;
    $transactionalSmsConfigured = data_get($page, 'consents.transactional.sms', true) === true;
    $marketingEmailConfigured = data_get($page, 'consents.marketing.email', true) === true;
    $marketingSmsConfigured = data_get($page, 'consents.marketing.sms', true) === true;

    $transactionalEmailAvailable = $transactionalEmailConfigured
        && in_array('email', $transactionalChannels, true);
    $transactionalSmsAvailable = $transactionalSmsConfigured
        && in_array('sms', $transactionalChannels, true);
    $marketingEmailAvailable = $marketingEmailConfigured
        && in_array('email', $marketingChannels, true);
    $marketingSmsAvailable = $marketingSmsConfigured
        && in_array('sms', $marketingChannels, true);

    $transactionalConsentAvailable = $transactionalEmailAvailable || $transactionalSmsAvailable;
    $marketingConsentAvailable = $marketingEmailAvailable || $marketingSmsAvailable;
    $smsAvailable = $transactionalSmsAvailable || $marketingSmsAvailable;

    $smsConsentModels = array_values(array_filter([
        $transactionalSmsAvailable ? 'transactionalSmsConsent' : null,
        $marketingSmsAvailable ? 'marketingSmsConsent' : null,
    ]));
    $smsConsentRequirement = implode(' || ', $smsConsentModels);

    $botReadyValue = (string) config(
        'webinars.registration.bot_protection.ready_value',
        'ready',
    );
    $botInteractionValue = (string) config(
        'webinars.registration.bot_protection.interaction_value',
        'human',
    );

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
                action="{{ \Illuminate\Support\Facades\URL::signedRoute(
                    'webinar.registration.store',
                    [
                        'seriesSlug' => $series->slug,
                        'webinar_id' => $webinar->getKey(),
                    ],
                    absolute: false,
                ) }}"
                class="{{ $tokens['form_grid'] ?? 'space-y-4' }}"
                x-data="{
                    transactionalEmailConsent: @js((bool) old('transactional_email_consent')),
                    transactionalSmsConsent: @js((bool) old('transactional_sms_consent')),
                    marketingSmsConsent: @js((bool) old('marketing_sms_consent')),
                    registrationFormReady: '',
                    registrationFormInteracted: '',
                    transactionalConsentError: false,
                    submitting: false,
                    submitRegistration(event) {
                        if (! this.transactionalEmailConsent && ! this.transactionalSmsConsent) {
                            event.preventDefault()
                            this.transactionalConsentError = true
                            this.$nextTick(() => this.$refs.transactionalConsentGroup?.focus())

                            return
                        }

                        if (! this.registrationFormReady || ! this.registrationFormInteracted) {
                            event.preventDefault()

                            return
                        }

                        this.submitting = true
                    },
                }"
                x-init="window.setTimeout(() => registrationFormReady = @js($botReadyValue), 750)"
                @focusin="registrationFormInteracted = @js($botInteractionValue)"
                @input="registrationFormInteracted = @js($botInteractionValue)"
                @change="registrationFormInteracted = @js($botInteractionValue); transactionalConsentError = false"
                @keydown="registrationFormInteracted = @js($botInteractionValue)"
                @pointerdown="registrationFormInteracted = @js($botInteractionValue)"
                @submit="submitRegistration($event)"
            >
                @csrf

                <input
                    type="hidden"
                    name="registration_form_ready"
                    x-model="registrationFormReady"
                >

                <input
                    type="hidden"
                    name="registration_form_interacted"
                    x-model="registrationFormInteracted"
                >

                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute -left-[10000px] top-auto h-px w-px overflow-hidden"
                >
                    <label for="company_website">Company website</label>
                    <input
                        id="company_website"
                        name="company_website"
                        type="text"
                        value=""
                        tabindex="-1"
                        autocomplete="off"
                    >
                </div>

                @error('registration_form')
                    <div
                        role="alert"
                        class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
                    >
                        {{ $message }}
                    </div>
                @enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.form.label for="first_name">
                            {{ $page['fields']['first_name']['label'] ?? 'First Name' }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="first_name"
                            name="first_name"
                            required
                            autocomplete="given-name"
                            maxlength="100"
                            :aria-invalid="$errors->has('first_name') ? 'true' : 'false'"
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
                            autocomplete="family-name"
                            maxlength="100"
                            :aria-invalid="$errors->has('last_name') ? 'true' : 'false'"
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
                            inputmode="email"
                            autocomplete="email"
                            maxlength="255"
                            :aria-invalid="$errors->has('email') ? 'true' : 'false'"
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

                        @if($smsAvailable)
                            <x-ui.form.input
                                id="phone"
                                name="phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel-national"
                                x-mask="(999) 999-9999"
                                pattern="\(\d{3}\) \d{3}-\d{4}"
                                maxlength="14"
                                title="Enter a 10-digit phone number, including the area code."
                                :aria-invalid="$errors->has('phone') ? 'true' : 'false'"
                                aria-describedby="phone_sms_helper"
                                x-bind:required="{{ $smsConsentRequirement }}"
                                x-bind:aria-required="({{ $smsConsentRequirement }}) ? 'true' : 'false'"
                                :value="old('phone', $registrationPrefill['phone'] ?? null)"
                                :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                            />
                        @else
                            <x-ui.form.input
                                id="phone"
                                name="phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel-national"
                                x-mask="(999) 999-9999"
                                pattern="\(\d{3}\) \d{3}-\d{4}"
                                maxlength="14"
                                title="Enter a 10-digit phone number, including the area code."
                                :aria-invalid="$errors->has('phone') ? 'true' : 'false'"
                                :value="old('phone', $registrationPrefill['phone'] ?? null)"
                                :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                            />
                        @endif

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

                @if($transactionalConsentAvailable)
                    <fieldset
                        x-ref="transactionalConsentGroup"
                        tabindex="-1"
                        class="mx-2 rounded-2xl bg-slate-50 pt-2 pb-4 px-4 border border-slate-300 focus:outline-none focus:ring-2 focus:ring-primary/30"
                    >
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
                                        x-model="transactionalEmailConsent"
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

                    <p
                        x-cloak
                        x-show="transactionalConsentError"
                        role="alert"
                        class="{{ $tokens['field_error'] ?? 'mt-3 text-sm text-red-600' }}"
                    >
                        Choose email or text so we can send your webinar details.
                    </p>

                    @error('transactional_consent')
                        <p class="{{ $tokens['field_error'] ?? 'mt-3 text-sm text-red-600' }}">
                            {{ $message }}
                        </p>
                    @enderror
                    </fieldset>
                @endif

                @if($marketingConsentAvailable)
                    <fieldset class="mx-2 rounded-2xl border border-slate-200 py-2 px-4">
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
                    </fieldset>
                @endif

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

                <x-ui.button
                    type="submit"
                    x-bind:disabled="submitting || ! registrationFormReady"
                    x-bind:aria-busy="submitting ? 'true' : 'false'"
                    class="{{ $tokens['primary_button'] ?? 'w-full' }} disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span x-show="! submitting">
                        {{ $page['submit']['label'] ?? 'Reserve My Spot' }}
                    </span>
                    <span x-cloak x-show="submitting">Submitting…</span>
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
