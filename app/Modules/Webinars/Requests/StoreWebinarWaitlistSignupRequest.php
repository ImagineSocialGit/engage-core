<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebinarWaitlistSignupRequest extends FormRequest
{
    private const SURFACE = 'webinar_waitlists';
    private const PURPOSE = 'marketing';
    private const SCOPE = 'webinar_waitlist';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
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
            'marketing_email_consent' => ['required', 'boolean'],
            'marketing_sms_consent' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnavailableSelectedChannels($validator);

                if (! $this->hasSelectedAvailableMarketingChannel()) {
                    $validator->errors()->add(
                        'marketing_consent',
                        'Please choose at least one available way to be notified when the next webinar is scheduled.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Since you checked SMS consent, please enter a phone number.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function acceptedMarketingChannels(): array
    {
        $selected = [];

        foreach (['email', 'sms'] as $channel) {
            if ($this->boolean("marketing_{$channel}_consent")) {
                $selected[] = $channel;
            }
        }

        return app(MessageChannelAvailability::class)->normalizeVisibleChannelsForSurface(
            channels: $selected,
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
        );
    }

    public function phone(): ?string
    {
        $phone = $this->validated('phone');

        return is_string($phone) && trim($phone) !== ''
            ? trim($phone)
            : null;
    }

    private function requiresPhoneNumber(): bool
    {
        return $this->boolean('marketing_sms_consent')
            && $this->channelAvailable('sms');
    }

    private function hasSelectedAvailableMarketingChannel(): bool
    {
        return $this->acceptedMarketingChannels() !== [];
    }

    private function rejectUnavailableSelectedChannels(Validator $validator): void
    {
        foreach (['email', 'sms'] as $channel) {
            $field = "marketing_{$channel}_consent";

            if ($this->boolean($field) && ! $this->channelAvailable($channel)) {
                $validator->errors()->add(
                    $field,
                    'This communication channel is not available for this waitlist form.'
                );
            }
        }
    }

    private function channelAvailable(string $channel): bool
    {
        return app(MessageChannelAvailability::class)->isVisibleForSurface(
            channel: $channel,
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
        );
    }
}
