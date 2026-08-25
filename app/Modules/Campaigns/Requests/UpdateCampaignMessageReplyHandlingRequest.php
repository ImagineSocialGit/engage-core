<?php

namespace App\Modules\Campaigns\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCampaignMessageReplyHandlingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_chain_version_id' => ['required', 'integer', 'min:1'],
            'reply_profile_key' => [
                'nullable',
                'string',
                'max:96',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
        ];
    }

    public function expectedVersionId(): int
    {
        return (int) $this->validated('message_chain_version_id');
    }

    public function replyProfileKey(): ?string
    {
        $value = $this->validated('reply_profile_key');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}