<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastMessageTokenValidator;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreBroadcastRequest extends FormRequest
{
    use NormalizesContactFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'broadcast_type' => ['required', 'string', Rule::in([
                Broadcast::BROADCAST_TYPE_REGULAR,
                Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ])],
            'intent' => ['required', 'string', Rule::in(['draft', 'schedule'])],
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['nullable', 'string', Rule::in(['email', 'sms'])],
            'subject' => [
                Rule::requiredIf(fn (): bool => $this->isPermissionInvitationRequest() || $this->regularBroadcastChannelInput() === 'email'),
                'nullable',
                'string',
                'max:255',
            ],
            'body' => [
                Rule::requiredIf(fn (): bool => $this->isPermissionInvitationRequest() || $this->regularBroadcastChannelInput() === 'email'),
                'nullable',
                'string',
            ],
            'message' => [
                Rule::requiredIf(fn (): bool => $this->isRegularBroadcastRequest() && $this->regularBroadcastChannelInput() === 'sms'),
                'nullable',
                'string',
                'max:1600',
            ],
            'token_fallbacks_present' => ['nullable', 'boolean'],
            'token_fallbacks' => ['nullable', 'array', 'max:50'],
            'token_fallbacks.*' => ['required', 'array'],
            'token_fallbacks.*.token' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/',
            ],
            'token_fallbacks.*.missing_behavior' => [
                'required',
                'string',
                Rule::in(MessageTokenFallbackResolver::BEHAVIORS),
            ],
            'token_fallbacks.*.fallback' => ['nullable', 'string', 'max:2000'],
            'token_fallbacks.*.segment' => ['nullable', 'string', 'max:5000'],
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
            criteriaField: 'recipient_criteria',
        ));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->isRegularBroadcastRequest()) {
                return;
            }

            $channel = $this->regularBroadcastChannelInput();
            $payload = $this->regularBroadcastPayloadFromInput($channel);
            $issues = app(BroadcastMessageTokenValidator::class)->issues(
                payload: $payload,
                channel: $channel,
            );

            foreach ($issues as $issue) {
                if (($issue['level'] ?? null) !== 'error') {
                    continue;
                }

                $validator->errors()->add(
                    $this->formErrorPath($issue['path'] ?? null),
                    is_string($issue['message'] ?? null) && trim($issue['message']) !== ''
                        ? trim($issue['message'])
                        : 'The Broadcast message contains an invalid dynamic field.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastAttributes(): array
    {
        $validated = $this->validated();
        $broadcastType = $this->broadcastType($validated);

        if ($broadcastType === Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION) {
            return $this->permissionInvitationAttributes($validated);
        }

        return $this->regularBroadcastAttributes($validated);
    }

    public function shouldSchedule(): bool
    {
        return $this->validated('intent') === 'schedule';
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function regularBroadcastAttributes(array $validated): array
    {
        $recipientFilter = $this->contactFilterAttributes(
            validated: $validated,
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
            criteriaField: 'recipient_criteria',
        );

        $recipientFilter = $this->withRecipientExclusions($recipientFilter, $validated);

        $channel = $this->regularBroadcastChannel($validated);

        return [
            'user_id' => $this->user()?->getKey(),
            'name' => $validated['name'],
            'channel' => $channel,
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
            'queue' => 'marketing',
            'status' => Broadcast::STATUS_DRAFT,
            'send_at' => $validated['send_at'] ?? null,
            'payload' => $this->regularBroadcastPayload($channel, $validated),
            'recipient_filter' => $recipientFilter,
            'recipient_count' => 0,
            'scheduled_count' => 0,
            'meta' => [
                'created_from' => 'crm',
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function permissionInvitationAttributes(array $validated): array
    {
        return [
            'user_id' => $this->user()?->getKey(),
            'name' => $validated['name'],
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'queue' => 'emails',
            'status' => Broadcast::STATUS_DRAFT,
            'send_at' => $validated['send_at'] ?? null,
            'payload' => [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ],
            'recipient_filter' => $this->permissionInvitationRecipientFilter($validated),
            'recipient_count' => 0,
            'scheduled_count' => 0,
            'meta' => [
                'created_from' => 'crm',
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'permission_invitation' => [
                    'source' => 'imported_contact',
                    'one_time' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function broadcastType(array $validated): string
    {
        return $validated['broadcast_type'] === Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION
            ? Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION
            : Broadcast::BROADCAST_TYPE_REGULAR;
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
        $payload = $channel === 'sms'
            ? ['message' => $validated['message']]
            : [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ];

        if (! $this->hasTokenFallbackSubmission($validated)) {
            return $payload;
        }

        $candidate = $payload;
        $candidate['token_fallbacks'] = array_values(
            is_array($validated['token_fallbacks'] ?? null)
                ? $validated['token_fallbacks']
                : [],
        );

        $policies = app(MessageTokenFallbackResolver::class)->policies($candidate);

        if ($policies !== []) {
            $payload['token_fallbacks'] = $policies;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function regularBroadcastPayloadFromInput(string $channel): array
    {
        $payload = $channel === 'sms'
            ? ['message' => (string) $this->input('message', '')]
            : [
                'subject' => (string) $this->input('subject', ''),
                'body' => (string) $this->input('body', ''),
            ];

        if ($this->hasTokenFallbackSubmission($this->all())) {
            $payload['token_fallbacks'] = $this->tokenFallbackInputForValidation();
        }

        return $payload;
    }

    /** @return array<int, mixed> */
    private function tokenFallbackInputForValidation(): array
    {
        $raw = $this->input('token_fallbacks', []);

        if (! is_array($raw) || ! array_is_list($raw)) {
            return is_array($raw) ? $raw : [];
        }

        return array_map(function (mixed $policy): mixed {
            if (! is_array($policy)) {
                return $policy;
            }

            $behavior = is_string($policy['missing_behavior'] ?? null)
                ? trim($policy['missing_behavior'])
                : null;

            if (in_array($behavior, [
                MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE,
                MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
            ], true) && ! is_string($policy['fallback'] ?? null)) {
                $policy['fallback'] = '';
            }

            return $policy;
        }, $raw);
    }

    /** @param array<string, mixed> $values */
    private function hasTokenFallbackSubmission(array $values): bool
    {
        if (array_key_exists('token_fallbacks', $values)) {
            return true;
        }

        return in_array(
            $values['token_fallbacks_present'] ?? null,
            [true, 1, '1', 'true', 'on'],
            true,
        );
    }

    private function formErrorPath(mixed $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return 'body';
        }

        $path = trim($path);

        return str_starts_with($path, 'payload.')
            ? substr($path, strlen('payload.'))
            : $path;
    }

    /**
    * @param array<string, mixed>|null $validated
    */
    private function regularBroadcastChannelInput(?array $validated = null): string
    {
        $value = $validated['channel'] ?? $this->input('channel', 'email');

        return $value === 'sms' ? 'sms' : 'email';
    }

    private function isPermissionInvitationRequest(): bool
    {
        return $this->input('broadcast_type') === Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION;
    }

    private function isRegularBroadcastRequest(): bool
    {
        return ! $this->isPermissionInvitationRequest();
    }

}