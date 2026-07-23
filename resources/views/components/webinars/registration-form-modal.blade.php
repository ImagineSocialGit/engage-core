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
    $questionSection = is_array($page['questions_section'] ?? null)
        ? $page['questions_section']
        : [];
    $registrationQuestions = app(
        \App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver::class,
    )->resolve($page['questions'] ?? []);
    $questionInputClass = data_get(
        $style,
        'components.input.base',
        'block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-ink shadow-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20',
    );

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

    $transactionalConsentOrder = collect(
        data_get($page, 'consents.transactional.order', ['email', 'sms']),
    )
        ->filter(fn (mixed $channel): bool => in_array($channel, ['email', 'sms'], true))
        ->unique()
        ->values();

    foreach (['email', 'sms'] as $channel) {
        if (! $transactionalConsentOrder->contains($channel)) {
            $transactionalConsentOrder->push($channel);
        }
    }

    $requiredTransactionalChannels = collect(
        data_get($page, 'consents.transactional.required_channels', []),
    )
        ->filter(fn (mixed $channel): bool => in_array($channel, ['email', 'sms'], true))
        ->unique()
        ->values();

    $transactionalEmailRequired = $requiredTransactionalChannels->contains('email');
    $transactionalSmsRequired = $requiredTransactionalChannels->contains('sms');
    $hasExplicitRequiredTransactionalChannels = $requiredTransactionalChannels->isNotEmpty();

    $combinedMarketingConfigured = data_get($page, 'consents.marketing.combined', false) === true;
    $combinedMarketingAvailable = $combinedMarketingConfigured
        && $marketingEmailAvailable
        && $marketingSmsAvailable;

    $transactionalConsentAvailable = $transactionalEmailAvailable || $transactionalSmsAvailable;
    $marketingConsentAvailable = $combinedMarketingAvailable
        || $marketingEmailAvailable
        || $marketingSmsAvailable;
    $smsAvailable = $transactionalSmsAvailable || $marketingSmsAvailable;

    $marketingConsentAvailable = false;
    
    $smsConsentModels = array_values(array_filter([
        $transactionalSmsAvailable ? 'transactionalSmsConsent' : null,
        $combinedMarketingAvailable
            ? 'marketingConsent'
            : ($marketingSmsAvailable ? 'marketingSmsConsent' : null),
    ]));
    $smsConsentRequirement = $smsConsentModels === []
        ? 'false'
        : implode(' || ', $smsConsentModels);

    $transactionalConsentErrorMessage = $notificationSection['error']
        ?? ($transactionalEmailRequired
            ? 'Choose email so we can send your webinar confirmation and access details.'
            : 'Choose email or text so we can send your webinar details.');

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


    $disclosureReferenceMarkerClass = data_get(
        $style,
        'disclosures.reference_marker',
        'ml-0.5 align-super text-[0.7em] font-semibold text-current',
    );
    $disclosureDefinitions = data_get($page, 'disclosures.items', []);
    $disclosureDefinitions = collect(
        is_array($disclosureDefinitions) ? $disclosureDefinitions : [],
    )
        ->mapWithKeys(function (mixed $definition, mixed $key): array {
            if (! is_string($key)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $key) !== 1
                || ! is_array($definition)
                || ! is_string($definition['text'] ?? null)
                || trim($definition['text']) === ''
            ) {
                return [];
            }

            $marker = $definition['marker'] ?? null;
            $marker = is_scalar($marker) ? trim((string) $marker) : null;

            $label = $definition['label'] ?? null;
            $label = is_string($label) ? trim($label) : null;

            return [
                $key => [
                    'key' => $key,
                    'id' => 'webinar-registration-'
                        .str_replace('_', '-', $key)
                        .'-disclosure',
                    'marker' => $marker !== '' ? $marker : null,
                    'label' => $label !== '' ? $label : null,
                    'text' => trim($definition['text']),
                ],
            ];
        });

    $resolveDisclosureReferences = static function (
        mixed $references,
    ) use ($disclosureDefinitions): array {
        if (! is_array($references)) {
            return [];
        }

        return collect($references)
            ->filter(fn (mixed $reference): bool =>
                is_string($reference)
                && trim($reference) !== ''
                && $disclosureDefinitions->has(trim($reference))
            )
            ->map(fn (string $reference): array =>
                $disclosureDefinitions->get(trim($reference))
            )
            ->unique('key')
            ->values()
            ->all();
    };

    $descriptionIds = static function (
        array $referenceItems,
        ?string $helperId = null,
        ?string $inlineDisclosureId = null,
    ): string {
        return collect([
            $helperId,
            ...array_column($referenceItems, 'id'),
            $referenceItems === [] ? $inlineDisclosureId : null,
        ])
            ->filter(fn (mixed $id): bool =>
                is_string($id) && trim($id) !== ''
            )
            ->unique()
            ->implode(' ');
    };

    $transactionalEmailReferenceItems = $resolveDisclosureReferences(
        data_get($page, 'fields.consent_messages.email.disclosure_refs', []),
    );
    $transactionalSmsReferenceItems = $resolveDisclosureReferences(
        data_get($page, 'fields.consent_messages.sms.disclosure_refs', []),
    );
    $marketingCombinedReferenceItems = $resolveDisclosureReferences(
        data_get(
            $page,
            'fields.marketing_consent_messages.combined.disclosure_refs',
            [],
        ),
    );
    $marketingEmailReferenceItems = $resolveDisclosureReferences(
        data_get(
            $page,
            'fields.marketing_consent_messages.email.disclosure_refs',
            [],
        ),
    );
    $marketingSmsReferenceItems = $resolveDisclosureReferences(
        data_get(
            $page,
            'fields.marketing_consent_messages.sms.disclosure_refs',
            [],
        ),
    );

    $transactionalEmailInlineDisclosure = data_get(
        $page,
        'fields.consent_messages.email.disclosure',
    );
    $transactionalSmsInlineDisclosure = data_get(
        $page,
        'fields.consent_messages.sms.disclosure',
    );
    $marketingCombinedInlineDisclosure = data_get(
        $page,
        'fields.marketing_consent_messages.combined.disclosure',
    );
    $marketingEmailInlineDisclosure = data_get(
        $page,
        'fields.marketing_consent_messages.email.disclosure',
    );
    $marketingSmsInlineDisclosure = data_get(
        $page,
        'fields.marketing_consent_messages.sms.disclosure',
    );

    $transactionalEmailDescribedBy = $descriptionIds(
        referenceItems: $transactionalEmailReferenceItems,
        helperId: filled(data_get(
            $page,
            'fields.consent_messages.email.helper',
        ))
            ? 'transactional_email_consent_helper'
            : null,
        inlineDisclosureId: filled($transactionalEmailInlineDisclosure)
            ? 'transactional_email_consent_disclosure'
            : null,
    );
    $transactionalSmsDescribedBy = $descriptionIds(
        referenceItems: $transactionalSmsReferenceItems,
        inlineDisclosureId: filled($transactionalSmsInlineDisclosure)
            ? 'transactional_sms_consent_disclosure'
            : null,
    );
    $marketingCombinedDescribedBy = $descriptionIds(
        referenceItems: $marketingCombinedReferenceItems,
        helperId: filled(data_get(
            $page,
            'fields.marketing_consent_messages.combined.helper',
        ))
            ? 'marketing_consent_helper'
            : null,
        inlineDisclosureId: filled($marketingCombinedInlineDisclosure)
            ? 'marketing_consent_disclosure'
            : null,
    );
    $marketingEmailDescribedBy = $descriptionIds(
        referenceItems: $marketingEmailReferenceItems,
        helperId: filled(data_get(
            $page,
            'fields.marketing_consent_messages.email.helper',
        ))
            ? 'marketing_email_consent_helper'
            : null,
        inlineDisclosureId: filled($marketingEmailInlineDisclosure)
            ? 'marketing_email_consent_disclosure'
            : null,
    );
    $marketingSmsDescribedBy = $descriptionIds(
        referenceItems: $marketingSmsReferenceItems,
        helperId: filled(data_get(
            $page,
            'fields.marketing_consent_messages.sms.helper',
        ))
            ? 'marketing_sms_consent_helper'
            : null,
        inlineDisclosureId: filled($marketingSmsInlineDisclosure)
            ? 'marketing_sms_consent_disclosure'
            : null,
    );

    $activeDisclosureKeys = collect();
    $rememberDisclosureItems = static function (
        array $items,
    ) use ($activeDisclosureKeys): void {
        foreach ($items as $item) {
            $activeDisclosureKeys->push($item['key']);
        }
    };

    if ($transactionalEmailAvailable && $transactionalEmailReferenceItems !== []) {
        $rememberDisclosureItems($transactionalEmailReferenceItems);
    }

    if ($transactionalSmsAvailable && $transactionalSmsReferenceItems !== []) {
        $rememberDisclosureItems($transactionalSmsReferenceItems);
    }

    if ($combinedMarketingAvailable) {
        $rememberDisclosureItems($marketingCombinedReferenceItems);
    } else {
        if ($marketingEmailAvailable && $marketingEmailReferenceItems !== []) {
            $rememberDisclosureItems($marketingEmailReferenceItems);
        }

        if ($marketingSmsAvailable && $marketingSmsReferenceItems !== []) {
            $rememberDisclosureItems($marketingSmsReferenceItems);
        }
    }

    $activeDisclosureKeys = $activeDisclosureKeys->unique()->values();
    $activeDisclosureItems = $disclosureDefinitions
        ->filter(fn (array $item, string $key): bool =>
            $activeDisclosureKeys->contains($key)
        );
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
                    marketingConsent: @js((bool) old('marketing_consent')),
                    marketingSmsConsent: @js((bool) old('marketing_sms_consent')),
                    transactionalEmailRequired: @js($transactionalEmailRequired),
                    transactionalSmsRequired: @js($transactionalSmsRequired),
                    hasExplicitRequiredTransactionalChannels: @js($hasExplicitRequiredTransactionalChannels),
                    registrationFormReady: '',
                    registrationFormInteracted: '',
                    transactionalConsentError: false,
                    submitting: false,
                    hasRequiredTransactionalConsent() {
                        if (this.transactionalEmailRequired && ! this.transactionalEmailConsent) {
                            return false
                        }

                        if (this.transactionalSmsRequired && ! this.transactionalSmsConsent) {
                            return false
                        }

                        if (! this.hasExplicitRequiredTransactionalChannels) {
                            return this.transactionalEmailConsent || this.transactionalSmsConsent
                        }

                        return true
                    },
                    submitRegistration(event) {
                        if (! this.hasRequiredTransactionalConsent()) {
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

                @if($registrationQuestions !== [] && ($questionSection['enabled'] ?? true))
                    <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                        @if(filled($questionSection['title'] ?? null))
                            <legend class="px-1 text-base font-semibold text-slate-900">
                                {{ $questionSection['title'] }}
                            </legend>
                        @endif

                        @if(filled($questionSection['body'] ?? null))
                            <p class="text-sm font-medium leading-6 text-slate-600">
                                {{ $questionSection['body'] }}
                            </p>
                        @endif

                        <div class="space-y-5 {{ filled($questionSection['body'] ?? null) ? 'mt-4' : '' }}">
                            @foreach($registrationQuestions as $question)
                                @php
                                    $questionKey = $question['key'];
                                    $answerPath = "registration_questions.{$questionKey}.answer";
                                    $otherPath = "registration_questions.{$questionKey}.other";
                                    $selectedAnswer = old($answerPath);
                                    $other = is_array($question['other'] ?? null)
                                        ? $question['other']
                                        : null;
                                @endphp

                                <div
                                    x-data="{ selectedAnswer: @js($selectedAnswer) }"
                                    class="space-y-2"
                                >
                                    <label
                                        for="registration_question_{{ $questionKey }}"
                                        class="block text-sm font-extrabold tracking-tight text-slate-900"
                                    >
                                        {{ $question['label'] }}
                                        @if($question['required'])
                                            <span aria-hidden="true" class="text-red-600">*</span>
                                            <span class="sr-only">Required</span>
                                        @endif
                                    </label>

                                    <select
                                        id="registration_question_{{ $questionKey }}"
                                        name="registration_questions[{{ $questionKey }}][answer]"
                                        x-model="selectedAnswer"
                                        class="{{ $questionInputClass }}"
                                        @if($question['required'])
                                            required
                                            aria-required="true"
                                        @endif
                                        aria-invalid="{{ $errors->has($answerPath) ? 'true' : 'false' }}"
                                    >
                                        <option value="">{{ $question['placeholder'] }}</option>

                                        @foreach($question['options'] as $option)
                                            <option
                                                value="{{ $option['key'] }}"
                                                @selected($selectedAnswer === $option['key'])
                                            >
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @if(filled($question['helper'] ?? null))
                                        <p class="text-xs font-medium leading-5 text-slate-500">
                                            {{ $question['helper'] }}
                                        </p>
                                    @endif

                                    @error($answerPath)
                                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                            {{ $message }}
                                        </p>
                                    @enderror

                                    @if($other !== null)
                                        <div
                                            x-cloak
                                            x-show="selectedAnswer === @js($other['option_key'])"
                                            x-transition.opacity
                                            class="space-y-2 pt-2"
                                        >
                                            <label
                                                for="registration_question_{{ $questionKey }}_other"
                                                class="block text-sm font-bold text-slate-900"
                                            >
                                                {{ $other['label'] }}
                                                @if($other['required'])
                                                    <span aria-hidden="true" class="text-red-600">*</span>
                                                    <span class="sr-only">Required</span>
                                                @endif
                                            </label>

                                            <textarea
                                                id="registration_question_{{ $questionKey }}_other"
                                                name="registration_questions[{{ $questionKey }}][other]"
                                                rows="3"
                                                maxlength="{{ $other['max_length'] }}"
                                                class="{{ $questionInputClass }} min-h-24 resize-y"
                                                @if(filled($other['placeholder'] ?? null))
                                                    placeholder="{{ $other['placeholder'] }}"
                                                @endif
                                                x-bind:required="selectedAnswer === @js($other['option_key']) && @js($other['required'])"
                                                x-bind:aria-required="selectedAnswer === @js($other['option_key']) && @js($other['required']) ? 'true' : 'false'"
                                                aria-invalid="{{ $errors->has($otherPath) ? 'true' : 'false' }}"
                                            >{{ old($otherPath) }}</textarea>

                                            <p class="text-xs font-medium leading-5 text-slate-500">
                                                Up to {{ number_format($other['max_length']) }} characters.
                                            </p>

                                            @error($otherPath)
                                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

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
                        @foreach($transactionalConsentOrder as $channel)
                            @if($channel === 'sms' && $transactionalSmsAvailable)
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
                                            @if($transactionalSmsRequired)
                                                required
                                                aria-required="true"
                                            @endif
                                            @if($transactionalSmsDescribedBy !== '')
                                                aria-describedby="{{ $transactionalSmsDescribedBy }}"
                                            @endif
                                            class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                        >

                                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                            {{ $page['fields']['consent_messages']['sms']['label'] ?? 'Text me webinar reminders and access information. (Optional)' }}
                                            @foreach($transactionalSmsReferenceItems as $disclosureItem)
                                                @if(filled($disclosureItem['marker']))
                                                    <sup
                                                        aria-hidden="true"
                                                        class="{{ $disclosureReferenceMarkerClass }}"
                                                    >{{ $disclosureItem['marker'] }}</sup>
                                                @endif
                                            @endforeach
                                        </span>
                                    </label>

                                    @if(
                                        $transactionalSmsReferenceItems === []
                                        && filled($transactionalSmsInlineDisclosure)
                                    )
                                        <p
                                            id="transactional_sms_consent_disclosure"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $transactionalSmsInlineDisclosure }}
                                        </p>
                                    @endif

                                    @error('transactional_sms_consent')
                                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            @elseif($channel === 'email' && $transactionalEmailAvailable)
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
                                            @if($transactionalEmailRequired)
                                                required
                                                aria-required="true"
                                            @endif
                                            @if($transactionalEmailDescribedBy !== '')
                                                aria-describedby="{{ $transactionalEmailDescribedBy }}"
                                            @endif
                                            class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                        >

                                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                            {{ $page['fields']['consent_messages']['email']['label'] ?? 'Email me webinar information.' }}
                                            @foreach($transactionalEmailReferenceItems as $disclosureItem)
                                                @if(filled($disclosureItem['marker']))
                                                    <sup
                                                        aria-hidden="true"
                                                        class="{{ $disclosureReferenceMarkerClass }}"
                                                    >{{ $disclosureItem['marker'] }}</sup>
                                                @endif
                                            @endforeach
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

                                    @if(
                                        $transactionalEmailReferenceItems === []
                                        && filled($transactionalEmailInlineDisclosure)
                                    )
                                        <p
                                            id="transactional_email_consent_disclosure"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $transactionalEmailInlineDisclosure }}
                                        </p>
                                    @endif

                                    @error('transactional_email_consent')
                                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <p
                        x-cloak
                        x-show="transactionalConsentError"
                        role="alert"
                        class="{{ $tokens['field_error'] ?? 'mt-3 text-sm text-red-600' }}"
                    >
                        {{ $transactionalConsentErrorMessage }}
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
                        @if($combinedMarketingAvailable)
                            <div>
                                <label
                                    for="marketing_consent"
                                    class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                                >
                                    <input
                                        id="marketing_consent"
                                        name="marketing_consent"
                                        type="checkbox"
                                        value="1"
                                        x-model="marketingConsent"
                                        @checked(old('marketing_consent'))
                                        @if($marketingCombinedDescribedBy !== '')
                                            aria-describedby="{{ $marketingCombinedDescribedBy }}"
                                        @endif
                                        class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                    >

                                    <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                        {{ $page['fields']['marketing_consent_messages']['combined']['label'] ?? 'Keep learning. Send me future webinar and educational emails and text messages.' }}
                                        @foreach($marketingCombinedReferenceItems as $disclosureItem)
                                            @if(filled($disclosureItem['marker']))
                                                <sup
                                                    aria-hidden="true"
                                                    class="{{ $disclosureReferenceMarkerClass }}"
                                                >{{ $disclosureItem['marker'] }}</sup>
                                            @endif
                                        @endforeach
                                    </span>
                                </label>

                                @if(filled($page['fields']['marketing_consent_messages']['combined']['helper'] ?? null))
                                    <p
                                        id="marketing_consent_helper"
                                        class="pl-7 text-xs leading-5 text-slate-500"
                                    >
                                        {{ $page['fields']['marketing_consent_messages']['combined']['helper'] }}
                                    </p>
                                @endif

                                @if(
                                    $marketingCombinedReferenceItems === []
                                    && filled($marketingCombinedInlineDisclosure)
                                )
                                    <p
                                        id="marketing_consent_disclosure"
                                        class="pl-7 text-xs leading-5 text-slate-500"
                                    >
                                        {{ $marketingCombinedInlineDisclosure }}
                                    </p>
                                @endif

                                @error('marketing_consent')
                                    <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        @else
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
                                            @if($marketingEmailDescribedBy !== '')
                                                aria-describedby="{{ $marketingEmailDescribedBy }}"
                                            @endif
                                            class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                        >

                                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                            {{ $page['fields']['marketing_consent_messages']['email']['label'] ?? 'Send me future webinar and educational emails.' }}
                                            @foreach($marketingEmailReferenceItems as $disclosureItem)
                                                @if(filled($disclosureItem['marker']))
                                                    <sup
                                                        aria-hidden="true"
                                                        class="{{ $disclosureReferenceMarkerClass }}"
                                                    >{{ $disclosureItem['marker'] }}</sup>
                                                @endif
                                            @endforeach
                                        </span>
                                    </label>

                                    @if(filled($page['fields']['marketing_consent_messages']['email']['helper'] ?? null))
                                        <p
                                            id="marketing_email_consent_helper"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $page['fields']['marketing_consent_messages']['email']['helper'] }}
                                        </p>
                                    @endif

                                    @if(
                                        $marketingEmailReferenceItems === []
                                        && filled($marketingEmailInlineDisclosure)
                                    )
                                        <p
                                            id="marketing_email_consent_disclosure"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $marketingEmailInlineDisclosure }}
                                        </p>
                                    @endif

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
                                            @if($marketingSmsDescribedBy !== '')
                                                aria-describedby="{{ $marketingSmsDescribedBy }}"
                                            @endif
                                            class="{{ $checkbox['input'] ?? 'mt-1 rounded border-slate-300 text-primary focus:ring-primary' }}"
                                        >

                                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }} font-medium">
                                            {{ $page['fields']['marketing_consent_messages']['sms']['label'] ?? 'Send me future webinar and educational texts.' }}
                                            @foreach($marketingSmsReferenceItems as $disclosureItem)
                                                @if(filled($disclosureItem['marker']))
                                                    <sup
                                                        aria-hidden="true"
                                                        class="{{ $disclosureReferenceMarkerClass }}"
                                                    >{{ $disclosureItem['marker'] }}</sup>
                                                @endif
                                            @endforeach
                                        </span>
                                    </label>

                                    @if(filled($page['fields']['marketing_consent_messages']['sms']['helper'] ?? null))
                                        <p
                                            id="marketing_sms_consent_helper"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $page['fields']['marketing_consent_messages']['sms']['helper'] }}
                                        </p>
                                    @endif

                                    @if(
                                        $marketingSmsReferenceItems === []
                                        && filled($marketingSmsInlineDisclosure)
                                    )
                                        <p
                                            id="marketing_sms_consent_disclosure"
                                            class="pl-7 text-xs leading-5 text-slate-500"
                                        >
                                            {{ $marketingSmsInlineDisclosure }}
                                        </p>
                                    @endif

                                    @error('marketing_sms_consent')
                                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            @endif
                        @endif
                    </div>
                    </fieldset>
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

                @if(
                    (($page['legal_links']['enabled'] ?? false) && $legalLinks->isNotEmpty())
                    || $activeDisclosureItems->isNotEmpty()
                )
                    <div class="{{ data_get($style, 'disclosures.footer', 'space-y-2') }}">
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
                    </div>
                @endif

                @if($activeDisclosureItems->isNotEmpty())
                    <x-ui.disclosures
                        :items="$activeDisclosureItems->values()->all()"
                        id-prefix="webinar-registration"
                        :style="$style['disclosures'] ?? []"
                    />
                @endif
            </form>
        </x-ui.card>
    </div>
</div>