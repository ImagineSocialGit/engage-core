<?php

namespace App\Modules\InboundMessaging\Actions\Sms;

use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsMessageClassifier;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsPurposeResolver;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsSenderResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;

class HandleInboundSmsWebhookAction
{
    public function __construct(
        private readonly RecordInboundMessageAction $recordInboundMessageAction,
        private readonly InboundMessageRouter $inboundMessageRouter,
        private readonly InboundSmsMessageClassifier $inboundSmsMessageClassifier,
        private readonly InboundSmsPurposeResolver $inboundSmsPurposeResolver,
        private readonly InboundSmsSenderResolver $inboundSmsSenderResolver,
    ) {}

    public function handle(SmsWebhookPayload $payload): ?string
    {
        if (! $payload->isInboundMessage) {
            return null;
        }

        $from = $this->inboundSmsSenderResolver->normalizePhone($payload->from);
        $to = $this->inboundSmsSenderResolver->normalizePhone($payload->to);
        $sender = $this->inboundSmsSenderResolver->resolve($payload->from);

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
            sender: $sender,
        );

        return $this->inboundMessageRouter->route($inboundMessage);
    }
}