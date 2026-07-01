<?php

namespace App\Modules\Broadcasts\Requests\Concerns;

use Illuminate\Validation\Rule;

trait NormalizesBroadcastRecipientFilter
{
    /**
     * @return array<string, mixed>
     */
    protected function recipientFilterRules(): array
    {
        return [
            'recipient_filter_type' => ['required', 'string', Rule::in(['all', 'tag', 'contact_ids'])],
            'recipient_tag' => ['nullable', 'string', 'max:100', 'required_if:recipient_filter_type,tag'],
            'contact_ids' => ['nullable', 'array', 'required_if:recipient_filter_type,contact_ids'],
            'contact_ids.*' => ['integer', Rule::exists('contacts', 'id')],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function recipientFilterAttributes(array $validated): array
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
                'contact_ids' => $this->normalizedContactIds($validated['contact_ids'] ?? []),
            ];
        }

        return [
            'type' => 'all',
        ];
    }

    /**
     * @param array<int, mixed> $contactIds
     * @return array<int, int>
     */
    private function normalizedContactIds(array $contactIds): array
    {
        return array_values(array_unique(array_map(
            fn (mixed $contactId): int => (int) $contactId,
            $contactIds,
        )));
    }
}