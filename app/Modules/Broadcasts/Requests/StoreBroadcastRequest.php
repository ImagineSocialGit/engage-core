<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Requests\Concerns\NormalizesBroadcastRecipientFilter;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    use NormalizesBroadcastRecipientFilter;

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
        ], $this->recipientFilterRules());
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
            'recipient_filter' => $this->recipientFilterAttributes($validated),
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