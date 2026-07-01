<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use Illuminate\Foundation\Http\FormRequest;

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

        return [
            'name' => $validated['name'],
            'send_at' => $validated['send_at'] ?? null,
            'payload' => [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
            ],
            'recipient_filter' => $broadcast instanceof Broadcast && $broadcast->isPermissionInvitation()
                ? ['type' => 'imported']
                : $this->contactFilterAttributes(
                    validated: $validated,
                    typeField: 'recipient_filter_type',
                    tagField: 'recipient_tag',
                    idsField: 'contact_ids',
                ),
        ];
    }
}