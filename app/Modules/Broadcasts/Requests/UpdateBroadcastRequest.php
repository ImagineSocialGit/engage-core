<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBroadcastRequest extends FormRequest
{
    use NormalizesContactFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'send_at' => ['nullable', 'date'],
            'exclude_broadcast_ids' => ['nullable', 'array'],
            'exclude_broadcast_ids.*' => ['integer', 'exists:broadcasts,id'],
            'exclude_broadcast_statuses' => ['nullable', 'array'],
            'exclude_broadcast_statuses.*' => ['string', Rule::in([
                BroadcastRecipient::STATUS_SCHEDULED,
                BroadcastRecipient::STATUS_SENT,
            ])],
        ], $this->contactFilterRules(
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastAttributes(): array
    {
        $validated = $this->validated();
        $broadcast = $this->route('broadcast');

        $recipientFilter = $broadcast instanceof Broadcast && $broadcast->isPermissionInvitation()
            ? ['type' => 'imported']
            : $this->withRecipientExclusions(
                recipientFilter: $this->contactFilterAttributes(
                    validated: $validated,
                    typeField: 'recipient_filter_type',
                    tagField: 'recipient_tag',
                    idsField: 'contact_ids',
                ),
                validated: $validated,
            );

        return [
            'name' => $validated['name'],
            'send_at' => $validated['send_at'] ?? null,
            'payload' => [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ],
            'recipient_filter' => $recipientFilter,
        ];
    }

    /**
     * @param array<string, mixed> $recipientFilter
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function withRecipientExclusions(array $recipientFilter, array $validated): array
    {
        $broadcastIds = $this->integerValues($validated['exclude_broadcast_ids'] ?? []);

        if ($broadcastIds === []) {
            return $recipientFilter;
        }

        $recipientFilter['exclude'] = [
            'broadcast_ids' => $broadcastIds,
            'statuses' => $this->excludedBroadcastRecipientStatuses($validated),
        ];

        return $recipientFilter;
    }

    /**
     * @return array<int, int>
     */
    private function integerValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, string>
     */
    private function excludedBroadcastRecipientStatuses(array $validated): array
    {
        $statuses = is_array($validated['exclude_broadcast_statuses'] ?? null)
            ? $validated['exclude_broadcast_statuses']
            : [];

        $allowed = [
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
        ];

        $statuses = array_values(array_unique(array_filter(
            $statuses,
            fn (mixed $status): bool => is_string($status) && in_array($status, $allowed, true),
        )));

        return $statuses === [] ? $allowed : $statuses;
    }
}