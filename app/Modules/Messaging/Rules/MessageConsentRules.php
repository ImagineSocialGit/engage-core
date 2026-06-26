<?php

namespace App\Modules\Messaging\Rules;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use Illuminate\Validation\Rule;

class MessageConsentRules
{
    public static function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(MessageChannel::values())],
            'purpose' => ['required', 'string', Rule::in(MessagePurpose::values())],
            'scope' => ['required', 'string', 'max:100'],
            'consented_at' => ['nullable', 'date'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ];
    }
}