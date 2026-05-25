<?php

namespace App\Rules\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use Illuminate\Validation\Rule;

class ConsentRevocationRules
{
    public static function rules(): array
    {
        return [
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'message_consent_id' => ['nullable', 'integer', 'exists:message_consents,id'],

            'channel' => ['required', 'string', Rule::in(MessageChannel::values())],
            'purpose' => ['required', 'string', Rule::in(MessagePurpose::values())],

            'reason' => ['required', 'string', Rule::in([
                'stop',
                'unsubscribe',
                'bounce',
                'complaint',
                'manual',
                'provider',
            ])],

            'revoked_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ];
    }
}