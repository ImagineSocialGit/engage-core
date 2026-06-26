<?php

namespace App\Modules\Tasks\Requests;

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
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where('is_active', true),
            ],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'notify_assignee' => ['sometimes', 'boolean'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        if (! isset($validated['related_type'], $validated['related_id'])) {
            return $validated;
        }

        $relatedTypeResolver = app(TaskRelatedTypeResolver::class);

        $validated['related_type'] = $relatedTypeResolver->normalize(
            $validated['related_type'],
        );

        if (! $relatedTypeResolver->exists(
            type: $validated['related_type'],
            id: $validated['related_id'],
        )) {
            throw ValidationException::withMessages([
                'related_id' => 'The selected related record is invalid.',
            ]);
        }

        return $validated;
    }
}