<?php

namespace App\Modules\Tasks\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['assignee_key' => ['nullable', 'string', 'max:512']];
    }

    protected function prepareForValidation(): void
    {
        $value = trim((string) $this->input('assignee_key', ''));
        $this->merge(['assignee_key' => $value !== '' ? $value : null]);
    }
}