<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'send_at' => ['nullable', 'date'],
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
        return [
            'user_id' => $this->user()?->getKey(),
            'name' => $validated['name'],
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'status' => Broadcast::STATUS_DRAFT,
            'send_at' => $validated['send_at'] ?? null,
            'payload' => [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ],
            'recipient_filter' => $this->contactFilterAttributes(
                validated: $validated,
                typeField: 'recipient_filter_type',
                tagField: 'recipient_tag',
                idsField: 'contact_ids',
            ),
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
            'recipient_filter' => [
                'type' => 'imported',
            ],
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
}