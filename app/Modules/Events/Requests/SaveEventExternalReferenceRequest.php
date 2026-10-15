<?php

namespace App\Modules\Events\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEventExternalReferenceRequest extends FormRequest
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
            'provider_key' => ['required', 'string', 'max:80'],
            'reference_type' => ['required', 'string', 'max:80'],
            'external_id' => ['nullable', 'string', 'max:191', 'required_without:url'],
            'url' => ['nullable', 'url', 'max:2048', 'required_without:external_id'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function referenceAttributes(): array
    {
        $validated = $this->validated();

        return [
            'provider_key' => trim((string) $validated['provider_key']),
            'reference_type' => trim((string) $validated['reference_type']),
            'external_id' => $this->nullableString($validated['external_id'] ?? null),
            'url' => $this->nullableString($validated['url'] ?? null),
            'label' => $this->nullableString($validated['label'] ?? null),
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