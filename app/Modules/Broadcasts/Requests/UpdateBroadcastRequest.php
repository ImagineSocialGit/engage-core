<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'channel' => ['nullable', 'string', Rule::in(['email', 'sms'])],
            'subject' => [
                Rule::requiredIf(fn (): bool => $this->isPermissionInvitationRoute() || $this->regularBroadcastChannelInput() === 'email'),
                'nullable',
                'string',
                'max:255',
            ],
            'body' => [
                Rule::requiredIf(fn (): bool => $this->isPermissionInvitationRoute() || $this->regularBroadcastChannelInput() === 'email'),
                'nullable',
                'string',
            ],
            'message' => [
                Rule::requiredIf(fn (): bool => ! $this->isPermissionInvitationRoute() && $this->regularBroadcastChannelInput() === 'sms'),
                'nullable',
                'string',
                'max:1600',
            ],
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

        if ($broadcast instanceof Broadcast && $broadcast->isPermissionInvitation()) {
            return [
                'name' => $validated['name'],
                'send_at' => $validated['send_at'] ?? null,
                'payload' => [
                    'subject' => $validated['subject'],
                    'body' => $validated['body'],
                ],
                'recipient_filter' => $this->permissionInvitationRecipientFilter($validated),
            ];
        }

        $channel = $this->regularBroadcastChannel($validated);

        $recipientFilter = $this->withRecipientExclusions(
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
            'channel' => $channel,
            'payload_class' => $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
            'send_at' => $validated['send_at'] ?? null,
            'payload' => $this->regularBroadcastPayload($channel, $validated),
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

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function permissionInvitationRecipientFilter(array $validated): array
    {
        $recipientFilter = $this->contactFilterAttributes(
            validated: $validated,
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
            importBatchIdsField: 'import_batch_ids',
        );

        if (($recipientFilter['type'] ?? null) !== 'import_batch') {
            return [
                'type' => 'imported',
            ];
        }

        return $recipientFilter['import_batch_ids'] === []
            ? ['type' => 'imported']
            : $recipientFilter;
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function regularBroadcastChannel(array $validated): string
    {
        $channel = $this->regularBroadcastChannelInput($validated);

        $availableChannels = app(MessageChannelAvailability::class)->visibleChannelsForSurface(
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
            requireProvider: false,
        );

        if (! in_array($channel, $availableChannels, true)) {
            throw ValidationException::withMessages([
                'channel' => 'That Broadcast channel is not currently available.',
            ]);
        }

        return $channel;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function regularBroadcastPayload(string $channel, array $validated): array
    {
        if ($channel === 'sms') {
            return [
                'message' => $validated['message'],
            ];
        }

        return [
            'subject' => $validated['subject'],
            'body' => $validated['body'],
        ];
    }

    /**
     * @param array<string, mixed>|null $validated
     */
    private function regularBroadcastChannelInput(?array $validated = null): string
    {
        $value = $validated['channel'] ?? $this->input('channel', $this->route('broadcast')?->channel ?? 'email');

        return $value === 'sms' ? 'sms' : 'email';
    }

    private function isPermissionInvitationRoute(): bool
    {
        $broadcast = $this->route('broadcast');

        return $broadcast instanceof Broadcast && $broadcast->isPermissionInvitation();
    }
}