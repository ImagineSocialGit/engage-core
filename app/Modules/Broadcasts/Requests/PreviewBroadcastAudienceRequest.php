<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewBroadcastAudienceRequest extends FormRequest
{
    use NormalizesContactFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            $this->contactFilterRules(
                typeField: 'recipient_filter_type',
                tagField: 'recipient_tag',
                idsField: 'contact_ids',
                criteriaField: 'recipient_criteria',
            ),
            [
                'exclude_broadcast_ids' => ['nullable', 'array'],
                'exclude_broadcast_ids.*' => ['integer', 'exists:broadcasts,id'],
                'exclude_broadcast_statuses' => ['nullable', 'array'],
                'exclude_broadcast_statuses.*' => ['string', Rule::in([
                    BroadcastRecipient::STATUS_SCHEDULED,
                    BroadcastRecipient::STATUS_SENT,
                ])],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientFilter(): array
    {
        $validated = $this->validated();
        $filter = $this->contactFilterAttributes(
            validated: $validated,
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
            criteriaField: 'recipient_criteria',
        );

        $broadcastIds = $this->integerValues(
            $validated['exclude_broadcast_ids'] ?? [],
        );

        if ($broadcastIds === []) {
            return $filter;
        }

        $filter['exclude'] = [
            'broadcast_ids' => $broadcastIds,
            'statuses' => $this->broadcastRecipientStatuses(
                $validated['exclude_broadcast_statuses'] ?? [],
            ),
        ];

        return $filter;
    }

    /** @return array<int, int> */
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

    /** @return array<int, string> */
    private function broadcastRecipientStatuses(mixed $values): array
    {
        $allowed = [
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
        ];

        if (! is_array($values)) {
            return $allowed;
        }

        $statuses = array_values(array_unique(array_filter(
            $values,
            fn (mixed $status): bool => is_string($status)
                && in_array($status, $allowed, true),
        )));

        return $statuses === [] ? $allowed : $statuses;
    }
}