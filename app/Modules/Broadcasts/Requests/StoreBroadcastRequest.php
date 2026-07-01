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
            ],
        ];
    }

    public function shouldSchedule(): bool
    {
        return $this->validated('intent') === 'schedule';
    }
}