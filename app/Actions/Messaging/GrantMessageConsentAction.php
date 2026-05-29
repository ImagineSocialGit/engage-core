<?php

namespace App\Actions\Messaging;

use App\Models\MessageConsent;
use App\Rules\Messaging\MessageConsentRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GrantMessageConsentAction
{
    /**
     * @throws ValidationException
     */
    public function handle(Model $recipient, array $data): MessageConsent
    {
        $validated = Validator::make($data, MessageConsentRules::rules())->validate();

        return MessageConsent::query()->updateOrCreate(
            [
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => $recipient->getKey(),
                'channel' => $validated['channel'],
                'purpose' => $validated['purpose'],
            ],
            [
                'consented_at' => $validated['consented_at'] ?? now(),
                'ip_address' => $validated['ip_address'] ?? null,
                'user_agent' => $validated['user_agent'] ?? null,
                'source' => $validated['source'] ?? null,
                'meta' => $validated['meta'] ?? null,
            ]
        );
    }
}