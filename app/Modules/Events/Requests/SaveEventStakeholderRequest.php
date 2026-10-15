<?php

namespace App\Modules\Events\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEventStakeholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role_key' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stakeholderAttributes(): array
    {
        $validated = $this->validated();

        return [
            'role_key' => trim((string) $validated['role_key']),
            'name' => trim((string) $validated['name']),
            'organization' => $this->nullableString($validated['organization'] ?? null),
            'email' => $this->nullableString($validated['email'] ?? null),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'notes' => $this->nullableString($validated['notes'] ?? null),
        ];
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