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
            'consent_messages' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent_messages.accepted' =>
                'Registering for this webinar requires accepting messages containing links to the event.',
        ];
    }
}