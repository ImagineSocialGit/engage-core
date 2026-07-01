<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Requests\Concerns\NormalizesBroadcastRecipientFilter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBroadcastRequest extends FormRequest
{
    use NormalizesBroadcastRecipientFilter;

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
        ], $this->recipientFilterRules());
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
}