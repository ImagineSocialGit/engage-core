<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWebinarRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'transactional_email_consent' => $this->boolean('transactional_email_consent'),
            'transactional_sms_consent' => $this->boolean('transactional_sms_consent'),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],

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
                if (
                    ! $this->boolean('transactional_email_consent')
                    && ! $this->boolean('transactional_sms_consent')
                ) {
                    $validator->errors()->add(
                        'transactional_consent',
                        'At least one of Email or SMS transactional messages containing links are required for this webinar.'
                    );
                }
            },
        ];
    }
}