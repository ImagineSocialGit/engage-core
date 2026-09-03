<?php

namespace App\Modules\Core\Requests;

use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contact = $this->route('contact');
        $uniqueEmail = Rule::unique('contacts', 'email');

        if ($contact instanceof Contact) {
            $uniqueEmail->ignore($contact->getKey());
        }

        return [
            'contact_edit_context' => [
                'required',
                Rule::in(['name', 'email', 'phone', 'details']),
            ],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', $uniqueEmail],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subsource' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['first_name', 'last_name', 'name', 'phone', 'source', 'subsource'] as $field) {
            if (! array_key_exists($field, $this->all())) {
                continue;
            }

            $normalized[$field] = $this->nullableString($this->input($field));
        }

        if (array_key_exists('email', $this->all())) {
            $email = $this->nullableString($this->input('email'));
            $normalized['email'] = $email !== null ? mb_strtolower($email) : null;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}