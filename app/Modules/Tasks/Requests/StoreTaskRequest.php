<?php

namespace App\Modules\Tasks\Requests;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        return [
            'links' => ['nullable', 'array'],
            'links.*.role' => [
                'required',
                'string',
                Rule::in(TaskLink::ROLES),
            ],
            'links.*.linkable_type' => [
                'required',
                'string',
            ],
            'links.*.linkable_id' => [
                'required',
                'integer',
            ],

            'assigned_to_type' => [
                'nullable',
                'string',
                'required_with:assigned_to_id',
            ],

            'assigned_to_id' => [
                'nullable',
                'integer',
                'required_with:assigned_to_type',
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

        $this->normalizeExistingMorph(
            validated: $validated,
            typeKey: 'assigned_to_type',
            idKey: 'assigned_to_id',
            invalidIdField: 'assigned_to_id',
        );

        $this->normalizeExistingMorph(
            validated: $validated,
            typeKey: 'responsible_type',
            idKey: 'responsible_id',
            invalidIdField: 'responsible_id',
        );

        $validated['links'] = $this->normalizeLinks(
            $validated['links'] ?? [],
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
    ): void {
        if (! isset($validated[$typeKey], $validated[$idKey])) {
            return;
        }

        $model = $this->resolveModel(
            type: $validated[$typeKey],
            id: $validated[$idKey],
            invalidField: $invalidIdField,
        );

        $validated[$typeKey] = $model->getMorphClass();
        $validated[$idKey] = (int) $model->getKey();
    }

    /**
     * @param array<int, mixed> $links
     * @return array<int, array{
     *     role: string,
     *     linkable_type: string,
     *     linkable_id: int
     * }>
     */
    private function normalizeLinks(array $links): array
    {
        $normalized = [];

        foreach ($links as $index => $link) {
            if (! is_array($link)) {
                continue;
            }

            $model = $this->resolveModel(
                type: $link['linkable_type'] ?? null,
                id: $link['linkable_id'] ?? null,
                invalidField: "links.{$index}.linkable_id",
            );

            $role = $link['role'];

            $attributes = [
                'role' => $role,
                'linkable_type' => $model->getMorphClass(),
                'linkable_id' => (int) $model->getKey(),
            ];

            $identity = implode(':', [
                $attributes['linkable_type'],
                $attributes['linkable_id'],
                $attributes['role'],
            ]);

            $normalized[$identity] = $attributes;
        }

        return array_values($normalized);
    }

    private function resolveModel(
        mixed $type,
        mixed $id,
        string $invalidField,
    ): Model {
        $modelClass = $this->modelClass($type);
        $modelId = is_numeric($id) ? (int) $id : null;

        if ($modelClass === null || $modelId === null) {
            throw ValidationException::withMessages([
                $invalidField => 'The selected record is invalid.',
            ]);
        }

        $model = $modelClass::query()->find($modelId);

        if (! $model instanceof Model) {
            throw ValidationException::withMessages([
                $invalidField => 'The selected record is invalid.',
            ]);
        }

        return $model;
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelClass(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $type = trim($type);
        $modelClass = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return null;
        }

        return $modelClass;
    }
}
