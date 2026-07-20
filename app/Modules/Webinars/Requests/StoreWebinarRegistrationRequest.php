<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreWebinarRegistrationRequest extends FormRequest
{
    private const SURFACE = 'webinar_registrations';

    private const HONEYPOT_FIELD = 'company_website';

    private const READY_FIELD = 'registration_form_ready';

    private const INTERACTION_FIELD = 'registration_form_interacted';

    /**
     * @var array<string, array{channel: string, purpose: string, scope: string, config_path: string}>
     */
    private const CONSENT_FIELDS = [
        'transactional_email_consent' => [
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'config_path' => 'transactional.email',
        ],
        'transactional_sms_consent' => [
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'config_path' => 'transactional.sms',
        ],
        'marketing_email_consent' => [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'config_path' => 'marketing.email',
        ],
        'marketing_sms_consent' => [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'config_path' => 'marketing.sms',
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $registrationConsentConfiguration = null;

    private bool $registerableWebinarResolved = false;

    private ?Webinar $resolvedRegisterableWebinar = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        $combinedMarketingConsent = $this->combinedMarketingConsentEnabled()
            ? $this->boolean('marketing_consent')
            : null;

        $this->merge([
            'first_name' => $this->trimmedString($this->input('first_name')),
            'last_name' => $this->nullableTrimmedString($this->input('last_name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'phone' => $this->nullableTrimmedString($phone),
            'webinar_id' => $this->query('webinar_id'),

            'transactional_email_consent' => $this->boolean('transactional_email_consent'),
            'transactional_sms_consent' => $this->boolean('transactional_sms_consent'),
            'marketing_consent' => $this->boolean('marketing_consent'),
            'marketing_email_consent' => $combinedMarketingConsent
                ?? $this->boolean('marketing_email_consent'),
            'marketing_sms_consent' => $combinedMarketingConsent
                ?? $this->boolean('marketing_sms_consent'),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'webinar_id' => ['required', 'integer', 'min:1'],

            'phone' => [
                Rule::requiredIf(fn (): bool => $this->requiresPhoneNumber()),
                'nullable',
                'string',
                'max:30',
                $this->validPhoneNumberRule(),
            ],

            'transactional_email_consent' => ['boolean'],
            'transactional_sms_consent' => ['boolean'],
            'marketing_consent' => ['boolean'],
            'marketing_email_consent' => ['boolean'],
            'marketing_sms_consent' => ['boolean'],

            self::HONEYPOT_FIELD => ['nullable', 'string', 'max:255'],
            self::READY_FIELD => ['nullable', 'string', 'max:20'],
            self::INTERACTION_FIELD => ['nullable', 'string', 'max:20'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->rejectLikelyAutomatedSubmission($validator)) {
                    return;
                }

                $this->rejectUnavailableSelectedChannels($validator);

                if (! $this->hasRequiredTransactionalConsent()) {
                    $validator->errors()->add(
                        'transactional_consent',
                        $this->transactionalConsentValidationMessage(),
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Enter a mobile phone number when selecting SMS.',
            'phone.max' => 'Enter a phone number with no more than 30 characters.',
        ];
    }

    private function validPhoneNumberRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            try {
                $normalized = app(PhoneNumberNormalizer::class)->normalize(
                    is_string($value) ? $value : null,
                );
            } catch (InvalidArgumentException) {
                $normalized = null;
            }

            if ($normalized === null) {
                $fail('Enter a valid phone number, including the area code.');
            }
        };
    }

    private function rejectLikelyAutomatedSubmission(Validator $validator): bool
    {
        if (! (bool) config('webinars.registration.bot_protection.enabled', true)) {
            return false;
        }

        $honeypot = $this->input(self::HONEYPOT_FIELD);
        $ready = $this->input(self::READY_FIELD);
        $interaction = $this->input(self::INTERACTION_FIELD);

        $expectedReady = (string) config(
            'webinars.registration.bot_protection.ready_value',
            'ready',
        );
        $expectedInteraction = (string) config(
            'webinars.registration.bot_protection.interaction_value',
            'human',
        );

        $honeypotFilled = is_string($honeypot)
            ? trim($honeypot) !== ''
            : $honeypot !== null;

        if (
            ! $honeypotFilled
            && is_string($ready)
            && hash_equals($expectedReady, $ready)
            && is_string($interaction)
            && hash_equals($expectedInteraction, $interaction)
        ) {
            return false;
        }

        $validator->errors()->add(
            'registration_form',
            'We could not verify this form submission. Refresh the page and try again.',
        );

        return true;
    }

    private function requiresPhoneNumber(): bool
    {
        foreach (['transactional_sms_consent', 'marketing_sms_consent'] as $field) {
            if ($this->boolean($field) && $this->consentFieldSelectable($field)) {
                return true;
            }
        }

        return false;
    }

    private function hasRequiredTransactionalConsent(): bool
    {
        $requiredChannels = $this->requiredTransactionalChannels();

        if ($requiredChannels === []) {
            foreach (['transactional_email_consent', 'transactional_sms_consent'] as $field) {
                if ($this->consentFieldSelectable($field) && $this->boolean($field)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($requiredChannels as $channel) {
            $field = "transactional_{$channel}_consent";

            if (! $this->consentFieldSelectable($field) || ! $this->boolean($field)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private function requiredTransactionalChannels(): array
    {
        $channels = data_get(
            $this->registrationConsentConfiguration(),
            'transactional.required_channels',
            [],
        );

        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $channels,
            fn (mixed $channel): bool => in_array($channel, ['email', 'sms'], true),
        )));
    }

    private function transactionalConsentValidationMessage(): string
    {
        $requiredChannels = $this->requiredTransactionalChannels();

        if ($requiredChannels === ['email']) {
            return 'Choose email so we can send your webinar confirmation and access details.';
        }

        if ($requiredChannels === ['sms']) {
            return 'Choose text messages so we can send your webinar access details.';
        }

        if ($requiredChannels !== []) {
            return 'Choose every required communication method for this webinar registration.';
        }

        return 'Choose at least one available method for receiving webinar details.';
    }

    private function rejectUnavailableSelectedChannels(Validator $validator): void
    {
        foreach (array_keys(self::CONSENT_FIELDS) as $field) {
            if ($this->boolean($field) && ! $this->consentFieldSelectable($field)) {
                $validator->errors()->add(
                    $field,
                    'This communication option is not available for this registration form.',
                );
            }
        }
    }

    private function combinedMarketingConsentEnabled(): bool
    {
        return data_get(
            $this->registrationConsentConfiguration(),
            'marketing.combined',
            false,
        ) === true
            && $this->consentFieldSelectable('marketing_email_consent')
            && $this->consentFieldSelectable('marketing_sms_consent');
    }

    private function consentFieldSelectable(string $field): bool
    {
        $definition = self::CONSENT_FIELDS[$field] ?? null;

        if (! is_array($definition)) {
            return false;
        }

        return $this->consentFieldConfigured($definition['config_path'])
            && $this->channelAvailable(
                $definition['channel'],
                $definition['purpose'],
                $definition['scope'],
            );
    }

    private function consentFieldConfigured(string $path): bool
    {
        return data_get($this->registrationConsentConfiguration(), $path, true) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationConsentConfiguration(): array
    {
        if ($this->registrationConsentConfiguration !== null) {
            return $this->registrationConsentConfiguration;
        }

        $seriesSlug = (string) $this->route('seriesSlug');

        $series = $seriesSlug !== ''
            ? WebinarSeries::query()
                ->where('slug', $seriesSlug)
                ->where('status', 'active')
                ->first()
            : null;

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: $seriesSlug,
            seriesMeta: is_array($series?->meta) ? $series->meta : [],
        );

        $consents = data_get($content, 'registration.consents', []);

        return $this->registrationConsentConfiguration = is_array($consents)
            ? $consents
            : [];
    }

    private function channelAvailable(string $channel, string $purpose, string $scope): bool
    {
        return app(MessageChannelAvailability::class)->isVisibleForSurface(
            channel: $channel,
            surface: self::SURFACE,
            purpose: $purpose,
            scope: $scope,
        );
    }

    public function registerableWebinar(): ?Webinar
    {
        if ($this->registerableWebinarResolved) {
            return $this->resolvedRegisterableWebinar;
        }

        $this->registerableWebinarResolved = true;

        $seriesSlug = trim((string) $this->route('seriesSlug'));
        $webinarId = filter_var(
            $this->query('webinar_id'),
            FILTER_VALIDATE_INT,
        );

        if ($seriesSlug === '' || $webinarId === false) {
            return null;
        }

        $series = app(GetActiveWebinarSeriesAction::class)->findBySlug(
            $seriesSlug,
        );

        if (! $series) {
            return null;
        }

        return $this->resolvedRegisterableWebinar = app(
            ResolveRegisterableWebinarAction::class,
        )->findForSeries(
            series: $series,
            webinarId: $webinarId,
        );
    }

    private function trimmedString(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrimmedString(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}