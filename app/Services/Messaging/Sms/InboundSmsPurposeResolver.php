<?php

namespace App\Services\Messaging\Sms;

class InboundSmsPurposeResolver
{
    public function resolve(SmsWebhookPayload $payload): ?string
    {
        $providerContextId = $payload->providerContextId;

        if ($providerContextId === null) {
            return null;
        }

        $profileIds = collect(config("sms.providers.{$payload->provider}.profile_ids", []));

        $matchedPurpose = $profileIds
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->search($providerContextId, true);

        return is_string($matchedPurpose)
            ? $matchedPurpose
            : null;
    }
}