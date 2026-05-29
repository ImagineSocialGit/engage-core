<?php

namespace App\Rules\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use Illuminate\Validation\Rule;

class ConsentRevocationRules
{
    public static function reasons(): array
    {
        return [
            'unsubscribe',
            'stop',
            'opt_out',
            'preference_update',
            'manual_request',
            'provider_unsubscribe',
        ];
    }

    public static function rules(): array
    {
        return [
            'message_consent_id' => ['nullable', 'integer', 'exists:message_consents,id'],
            'channel' => ['required', 'string', Rule::in(MessageChannel::values())],
            'purpose' => ['required', 'string', Rule::in(MessagePurpose::values())],
            'reason' => ['required', 'string', Rule::in(ConsentRevocation::reasons())],
            'revoked_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ];
    }
}