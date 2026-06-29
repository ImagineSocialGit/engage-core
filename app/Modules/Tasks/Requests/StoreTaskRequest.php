<?php

namespace App\Modules\Tasks\Requests;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskRelatedTypeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $relatedTypeResolver = app(TaskRelatedTypeResolver::class);

        return [
            'related_type' => [
                'nullable',
                'string',
                'required_with:related_id',
                Rule::in($relatedTypeResolver->allowedTypeKeys()),
            ],

            'related_id' => [
                'nullable',
                'integer',
                'required_with:related_type',
            ],

            'assigned_to_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where('is_active', true),
            ],

            'responsible_party' => [
                'nullable',
                'string',
                Rule::in(Task::RESPONSIBLE_PARTY_OPTIONS),
            ],

            'responsible_type' => [
                'nullable',
                'string',
                'required_with:responsible_id',
                Rule::in($relatedTypeResolver->allowedTypeKeys()),
            ],

            'responsible_id' => [
                'nullable',
                'integer',
                'required_with:responsible_type',
            ],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'max:50'],
            'notify_assignee' => ['sometimes', 'boolean'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        $validated['responsible_party'] ??= Task::RESPONSIBLE_PARTY_INTERNAL;

        $relatedTypeResolver = app(TaskRelatedTypeResolver::class);

        $this->normalizeExistingMorph(
            validated: $validated,
            typeKey: 'related_type',
            idKey: 'related_id',
            invalidIdField: 'related_id',
            relatedTypeResolver: $relatedTypeResolver,
        );

        $this->normalizeExistingMorph(
            validated: $validated,
            typeKey: 'responsible_type',
            idKey: 'responsible_id',
            invalidIdField: 'responsible_id',
            relatedTypeResolver: $relatedTypeResolver,
        );

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function normalizeExistingMorph(
        array &$validated,
        string $typeKey,
        string $idKey,
        string $invalidIdField,
        TaskRelatedTypeResolver $relatedTypeResolver,
    ): void {
        if (! isset($validated[$typeKey], $validated[$idKey])) {
            return;
        }

        $validated[$typeKey] = $relatedTypeResolver->normalize(
            $validated[$typeKey],
        );

        if (! $relatedTypeResolver->exists(
            type: $validated[$typeKey],
            id: $validated[$idKey],
        )) {
            throw ValidationException::withMessages([
                $invalidIdField => 'The selected record is invalid.',
            ]);
        }
    }
}