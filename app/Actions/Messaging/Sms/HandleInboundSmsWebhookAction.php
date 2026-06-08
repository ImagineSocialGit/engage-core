<?php

namespace App\Actions\Messaging\Sms;

use App\Actions\Messaging\RevokeMessageConsentAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Services\Messaging\Sms\SmsWebhookPayload;

class HandleInboundSmsWebhookAction
{
    public function __construct(
        private readonly RevokeMessageConsentAction $revokeMessageConsentAction,
    ) {}

    public function handle(SmsWebhookPayload $payload): ?string
    {
        $body = $payload->normalizedBody();
        $from = $payload->normalizedFrom();

        if ($body === null || $from === null) {
            return null;
        }

        if ($this->isStopKeyword($payload->provider, $body)) {
            $this->revokeSmsConsent($payload, $from, $body);

            return config("sms.providers.{$payload->provider}.webhooks.stop_response");
        }

        if ($this->isHelpKeyword($payload->provider, $body)) {
            return config("sms.providers.{$payload->provider}.webhooks.help_response");
        }

        if ($this->isStartKeyword($payload->provider, $body)) {
            return null;
        }

        return null;
    }

    private function revokeSmsConsent(
        SmsWebhookPayload $payload,
        string $from,
        string $keyword,
    ): void {
        $contact = Contact::query()
            ->where('phone', $from)
            ->first();

        if (! $contact) {
            return;
        }

        $consents = $contact
            ->messageConsents()
            ->where('channel', MessageChannel::Sms->value)
            ->get();

        if ($consents->isEmpty()) {
            $consents = collect([
                (object) [
                    'purpose' => MessagePurpose::Transactional->value,
                    'scope' => 'webinar',
                ],
                (object) [
                    'purpose' => MessagePurpose::Marketing->value,
                    'scope' => 'general_drip',
                ],
            ]);
        }

        foreach ($consents as $consent) {
            $this->revokeMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Sms->value,
                'purpose' => $consent->purpose,
                'scope' => $consent->scope,

                'reason' => ConsentRevocation::REASON_STOP,
                'source' => $payload->source,

                'ip_address' => $payload->ipAddress,
                'user_agent' => $payload->userAgent,

                'meta' => [
                    'provider' => $payload->provider,
                    'provider_message_id' => $payload->providerMessageId,
                    'from' => $payload->from,
                    'to' => $payload->to,
                    'body' => $payload->body,
                    'keyword' => $keyword,
                    'raw' => $payload->raw,
                ],
            ]);
        }
    }

    private function isStopKeyword(string $provider, string $body): bool
    {
        return in_array(
            strtolower($body),
            config("sms.providers.{$provider}.webhooks.stop_keywords", []),
            true,
        );
    }

    private function isHelpKeyword(string $provider, string $body): bool
    {
        return in_array(
            strtolower($body),
            config("sms.providers.{$provider}.webhooks.help_keywords", []),
            true,
        );
    }

    private function isStartKeyword(string $provider, string $body): bool
    {
        return in_array(
            strtolower($body),
            config("sms.providers.{$provider}.webhooks.start_keywords", []),
            true,
        );
    }
}