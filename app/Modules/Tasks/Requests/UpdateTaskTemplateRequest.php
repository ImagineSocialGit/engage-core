<?php

namespace App\Modules\Tasks\Requests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'task_description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:50'],
            'due_offset_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
            'responsible_party' => [
                'required',
                'string',
                Rule::in(Task::RESPONSIBLE_PARTY_OPTIONS),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => $this->nullableString('description'),
            'task_description' => $this->nullableString('task_description'),
            'priority' => $this->nullableString('priority'),
            'due_offset_minutes' => $this->filled('due_offset_minutes')
                ? $this->input('due_offset_minutes')
                : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    private function nullableString(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}