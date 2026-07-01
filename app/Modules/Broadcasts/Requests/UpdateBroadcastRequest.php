<?php

namespace App\Modules\Broadcasts\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'send_at' => ['nullable', 'date'],

            'recipient_filter_type' => ['required', 'string', Rule::in(['all', 'tag', 'contact_ids'])],
            'recipient_tag' => ['nullable', 'string', 'max:100', 'required_if:recipient_filter_type,tag'],
            'contact_ids' => ['nullable', 'array', 'required_if:recipient_filter_type,contact_ids'],
            'contact_ids.*' => ['integer', Rule::exists('contacts', 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'send_at' => $validated['send_at'] ?? null,
            'payload' => [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ],
            'recipient_filter' => $this->recipientFilterAttributes($validated),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function recipientFilterAttributes(array $validated): array
    {
        $type = $validated['recipient_filter_type'];

        if ($type === 'tag') {
            return [
                'type' => 'tag',
                'tags' => [$validated['recipient_tag']],
            ];
        }

        if ($type === 'contact_ids') {
            return [
                'type' => 'contact_ids',
                'contact_ids' => array_values(array_unique(array_map(
                    fn (mixed $contactId): int => (int) $contactId,
                    $validated['contact_ids'] ?? [],
                ))),
            ];
        }

        return [
            'type' => 'all',
        ];
    }
}