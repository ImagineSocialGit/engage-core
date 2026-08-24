<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Messaging\Requests\UpdateMessageTemplatePresetRequest;

final class UpdateCampaignMessageRequest extends UpdateMessageTemplatePresetRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'message_chain_version_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedVersionId(): int
    {
        return (int) $this->validated('message_chain_version_id');
    }
}