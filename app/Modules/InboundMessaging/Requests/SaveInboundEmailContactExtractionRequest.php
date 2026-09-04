<?php

namespace App\Modules\InboundMessaging\Requests;

use App\Modules\InboundMessaging\Services\Email\InboundEmailContactExtractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveInboundEmailContactExtractionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sourceValues = [
            InboundEmailContactExtractor::SOURCE_NONE,
            InboundEmailContactExtractor::SOURCE_SENDER_EMAIL,
            InboundEmailContactExtractor::SOURCE_REPLY_TO_EMAIL,
            InboundEmailContactExtractor::SOURCE_SUBJECT,
            InboundEmailContactExtractor::SOURCE_SUBJECT_AFTER_LABEL,
            InboundEmailContactExtractor::SOURCE_BODY_AFTER_LABEL,
        ];

        return [
            'enabled' => ['required', 'boolean'],
            'fields' => ['required', 'array'],
            'fields.email' => ['required', 'array'],
            'fields.email.source' => ['required', 'string', Rule::in($sourceValues)],
            'fields.email.label' => ['nullable', 'string', 'max:191'],
            'fields.first_name.source' => ['nullable', 'string', Rule::in($sourceValues)],
            'fields.first_name.label' => ['nullable', 'string', 'max:191'],
            'fields.last_name.source' => ['nullable', 'string', Rule::in($sourceValues)],
            'fields.last_name.label' => ['nullable', 'string', 'max:191'],
            'fields.name.source' => ['nullable', 'string', Rule::in($sourceValues)],
            'fields.name.label' => ['nullable', 'string', 'max:191'],
            'fields.phone.source' => ['nullable', 'string', Rule::in($sourceValues)],
            'fields.phone.label' => ['nullable', 'string', 'max:191'],
            'required_fields' => ['nullable', 'array'],
            'required_fields.*' => [
                'string',
                Rule::in([
                    'email',
                    'first_name',
                    'last_name',
                    'name',
                    'phone',
                ]),
            ],
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     definition: array<string, mixed>
     * }
     */
    public function extractionDefinition(): array
    {
        $validated = $this->validated();

        return [
            'enabled' => (bool) $validated['enabled'],
            'definition' => [
                'version' => InboundEmailContactExtractor::VERSION,
                'fields' => is_array($validated['fields'] ?? null)
                    ? $validated['fields']
                    : [],
                'required_fields' =>
                    is_array($validated['required_fields'] ?? null)
                        ? $validated['required_fields']
                        : [],
            ],
        ];
    }
}