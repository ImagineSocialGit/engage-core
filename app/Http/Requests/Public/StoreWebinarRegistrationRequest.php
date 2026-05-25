<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebinarRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],

            'transactional_email_consent' => ['accepted'],
            'transactional_sms_consent' => ['nullable', 'accepted'],
            'marketing_email_consent' => ['nullable', 'boolean'],
            'marketing_sms_consent' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'transactional_email_consent.accepted' => 'Registering for this webinar requires accepting transactional email messages containing links and event details.',
            'transactional_sms_consent.accepted' => 'SMS reminders require accepting transactional text messages for this webinar.',
        ];
    }
}