<?php

namespace App\Actions\Messaging\Sms;

use App\Actions\Messaging\RecordInboundMessageAction;
use App\Enums\MessageChannel;
use App\Services\Messaging\InboundMessageRouter;
use App\Services\Messaging\Sms\InboundSmsMessageClassifier;
use App\Services\Messaging\Sms\InboundSmsPurposeResolver;
use App\Services\Messaging\Sms\InboundSmsRecipientResolver;
use App\Services\Messaging\Sms\SmsWebhookPayload;

class HandleInboundSmsWebhookAction
{
    public function __construct(
        private readonly RecordInboundMessageAction $recordInboundMessageAction,
        private readonly InboundMessageRouter $inboundMessageRouter,
        private readonly InboundSmsMessageClassifier $inboundSmsMessageClassifier,
        private readonly InboundSmsPurposeResolver $inboundSmsPurposeResolver,
        private readonly InboundSmsRecipientResolver $inboundSmsRecipientResolver,
    ) {}

    public function handle(SmsWebhookPayload $payload): ?string
    {
        if (! $payload->isInboundMessage) {
            return null;
        }

        $from = $this->inboundSmsRecipientResolver->normalizePhone($payload->from);
        $to = $this->inboundSmsRecipientResolver->normalizePhone($payload->to);
        $recipient = $this->inboundSmsRecipientResolver->resolve($payload->from);

        $inboundMessage = $this->recordInboundMessageAction->handle(
            data: [
                'channel' => MessageChannel::Sms->value,
                'provider' => $payload->provider,
                'provider_event_id' => $payload->providerEventId,
                'provider_message_id' => $payload->providerMessageId,
                'provider_context_id' => $payload->providerContextId,
                'from_type' => 'phone',
                'from_value' => $from,
                'to_type' => 'phone',
                'to_value' => $to,
                'body' => $payload->trimmedBody(),
                'classification' => $this->inboundSmsMessageClassifier->classify(
                    provider: $payload->provider,
                    body: $payload->normalizedBody(),
                ),
                'purpose' => $this->inboundSmsPurposeResolver->resolve($payload),
                'scope' => null,
                'received_at' => $payload->receivedAt,
                'meta' => [
                    'event_type' => $payload->eventType,
                    'source' => $payload->source,
                    'ip_address' => $payload->ipAddress,
                    'user_agent' => $payload->userAgent,
                    'raw' => $payload->raw,
                ],
            ],
            recipient: $recipient,
        );

        return $this->inboundMessageRouter->route($inboundMessage);
    }
}