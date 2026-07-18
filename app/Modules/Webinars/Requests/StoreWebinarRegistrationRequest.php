<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebinarRegistrationRequest extends FormRequest
{
    private const SURFACE = 'webinar_registrations';

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
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'webinar_id' => $this->query('webinar_id'),

            'transactional_email_consent' => $this->boolean('transactional_email_consent'),
            'transactional_sms_consent' => $this->boolean('transactional_sms_consent'),
            'marketing_email_consent' => $this->boolean('marketing_email_consent'),
            'marketing_sms_consent' => $this->boolean('marketing_sms_consent'),
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
            ],

            'transactional_email_consent' => ['boolean'],
            'transactional_sms_consent' => ['boolean'],
            'marketing_email_consent' => ['boolean'],
            'marketing_sms_consent' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnavailableSelectedChannels($validator);

                if (! $this->hasSelectedAvailableTransactionalChannel()) {
                    $validator->errors()->add(
                        'transactional_consent',
                        'Consent to at least one available webinar notification channel is required.'
                    );
                }

                if ($this->duplicateRegistrationExists()) {
                    $validator->errors()->add(
                        'email',
                        'This email has already been used to register for this webinar.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Since you checked SMS consent fields, please enter a phone number.',
        ];
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

    private function hasSelectedAvailableTransactionalChannel(): bool
    {
        foreach (['transactional_email_consent', 'transactional_sms_consent'] as $field) {
            if ($this->consentFieldSelectable($field) && $this->boolean($field)) {
                return true;
            }
        }

        return false;
    }

    private function rejectUnavailableSelectedChannels(Validator $validator): void
    {
        foreach (array_keys(self::CONSENT_FIELDS) as $field) {
            if ($this->boolean($field) && ! $this->consentFieldSelectable($field)) {
                $validator->errors()->add(
                    $field,
                    'This communication option is not available for this registration form.'
                );
            }
        }
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

    private function duplicateRegistrationExists(): bool
    {
        $email = strtolower(trim((string) $this->input('email')));

        if ($email === '') {
            return false;
        }

        $webinar = $this->registerableWebinar();

        if (! $webinar) {
            return false;
        }

        return WebinarRegistration::query()
            ->where('webinar_id', $webinar->getKey())
            ->whereHas('contact', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->exists();
    }

}
