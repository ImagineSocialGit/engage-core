<?php

namespace App\Modules\Tasks\Requests;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskTemplateTimingResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaskTemplateRequest extends FormRequest
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
            'due_timing' => ['required', Rule::in(['none', 'immediately', 'after'])],
            'due_offset_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
            'due_offset_value' => ['nullable', 'required_if:due_timing,after', 'integer', 'min:1', 'max:5256000'],
            'due_offset_unit' => ['nullable', 'required_if:due_timing,after', Rule::in(TaskTemplateTimingResolver::UNITS)],
            'responsible_party' => ['required', Rule::in(Task::RESPONSIBLE_PARTY_OPTIONS)],
            'assignment_mode' => ['nullable', Rule::in(['unassigned', 'user', 'team', 'team_round_robin'])],
            'assigned_user_id' => ['nullable', 'required_if:assignment_mode,user', 'integer'],
            'assigned_team_id' => ['nullable', 'required_if:assignment_mode,team,team_round_robin', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $legacyMinutes = $this->filled('due_offset_minutes') ? (int) $this->input('due_offset_minutes') : null;

        $this->merge([
            'description' => $this->nullableString('description'),
            'task_description' => $this->nullableString('task_description'),
            'priority' => $this->nullableString('priority'),
            'is_active' => $this->boolean('is_active'),
            'due_timing' => $this->filled('due_timing')
                ? $this->input('due_timing')
                : ($legacyMinutes === null ? 'none' : ($legacyMinutes === 0 ? 'immediately' : 'after')),
            'due_offset_value' => $this->filled('due_offset_value')
                ? $this->input('due_offset_value')
                : ($legacyMinutes !== null && $legacyMinutes > 0 ? $legacyMinutes : null),
            'due_offset_unit' => $this->filled('due_offset_unit')
                ? $this->input('due_offset_unit')
                : ($legacyMinutes !== null && $legacyMinutes > 0 ? 'minutes' : null),
        ]);
    }

    private function nullableString(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}