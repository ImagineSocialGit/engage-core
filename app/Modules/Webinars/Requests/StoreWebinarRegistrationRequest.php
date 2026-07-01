<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebinarRegistrationRequest extends FormRequest
{
    private const SURFACE = 'webinar_registrations';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),

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

            'phone' => [
                Rule::requiredIf(fn (): bool => $this->requiresPhoneNumber()),
                'nullable',
                'string',
                'max:30',
            ],

            'transactional_email_consent' => ['required', 'boolean'],
            'transactional_sms_consent' => ['required', 'boolean'],

            'marketing_email_consent' => ['nullable', 'boolean'],
            'marketing_sms_consent' => ['nullable', 'boolean'],
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
        return (
            $this->boolean('transactional_sms_consent')
            && $this->channelAvailable('sms', 'transactional', 'webinar')
        ) || (
            $this->boolean('marketing_sms_consent')
            && $this->channelAvailable('sms', 'marketing', 'webinar_nurture')
        );
    }

    private function hasSelectedAvailableTransactionalChannel(): bool
    {
        foreach ($this->availableTransactionalChannels() as $channel) {
            if ($this->boolean("transactional_{$channel}_consent")) {
                return true;
            }
        }

        return false;
    }

    private function rejectUnavailableSelectedChannels(Validator $validator): void
    {
        foreach ([
            'transactional_email_consent' => ['email', 'transactional', 'webinar'],
            'transactional_sms_consent' => ['sms', 'transactional', 'webinar'],
            'marketing_email_consent' => ['email', 'marketing', 'webinar_nurture'],
            'marketing_sms_consent' => ['sms', 'marketing', 'webinar_nurture'],
        ] as $field => [$channel, $purpose, $scope]) {
            if (
                $this->boolean($field)
                && ! $this->channelAvailable($channel, $purpose, $scope)
            ) {
                $validator->errors()->add(
                    $field,
                    'This communication channel is not available for this registration form.'
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function availableTransactionalChannels(): array
    {
        return app(MessageChannelAvailability::class)->visibleChannelsForSurface(
            surface: self::SURFACE,
            purpose: 'transactional',
            scope: 'webinar',
        );
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

    private function duplicateRegistrationExists(): bool
    {
        $email = strtolower(trim((string) $this->input('email')));

        if ($email === '') {
            return false;
        }

        $seriesSlug = (string) $this->route('seriesSlug');

        if ($seriesSlug === '') {
            return false;
        }

        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->where('status', 'active')
            ->first();

        if (! $series) {
            return false;
        }

        $webinar = Webinar::query()
            ->where('webinar_series_id', $series->id)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        if (! $webinar) {
            return false;
        }

        return WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->whereHas('contact', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->exists();
    }
}
