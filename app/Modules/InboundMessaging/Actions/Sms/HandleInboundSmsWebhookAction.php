<?php

namespace App\Modules\InboundMessaging\Actions\Sms;

use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyTextNormalizer;
use App\Modules\InboundMessaging\Services\Reply\InboundSmsReplyCorrelator;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsMessageClassifier;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsPurposeResolver;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsSenderResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;
use App\Modules\Messaging\Enums\MessageChannel;
use InvalidArgumentException;

class HandleInboundSmsWebhookAction
{
    public function __construct(
        private readonly RecordInboundMessageAction $recordInboundMessageAction,
        private readonly ProcessInboundSmsMessageAction $processInboundSmsMessageAction,
        private readonly InboundSmsMessageClassifier $inboundSmsMessageClassifier,
        private readonly InboundSmsPurposeResolver $inboundSmsPurposeResolver,
        private readonly InboundSmsSenderResolver $inboundSmsSenderResolver,
        private readonly InboundSmsReplyCorrelator $replyCorrelator,
        private readonly InboundReplyTextNormalizer $replyTextNormalizer,
        private readonly InboundReplyIntentClassifier $replyIntentClassifier,
    ) {}

    public function handle(SmsWebhookPayload $payload): ?string
    {
        if (! $payload->isInboundMessage) {
            return null;
        }

        if ($payload->providerEventId === null
            && $payload->providerMessageId === null
        ) {
            throw new InvalidArgumentException(
                'Inbound SMS webhook requires a provider event or message identifier.',
            );
        }

        $from = $this->inboundSmsSenderResolver->normalizePhone($payload->from);
        $to = $this->inboundSmsSenderResolver->normalizePhone($payload->to);
        $sender = $this->inboundSmsSenderResolver->resolve($payload->from);
        $classification = $this->inboundSmsMessageClassifier->classify(
            provider: $payload->provider,
            body: $payload->normalizedBody(),
        );
        $correlated = $classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY
            && $sender !== null
                ? $this->replyCorrelator->correlate(
                    contact: $sender,
                    fromValue: $from,
                    receivedAt: $payload->receivedAt,
                )
                : null;
        $normalized = $this->replyTextNormalizer->normalize($payload->trimmedBody());
        $intent = $classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY
            ? $this->replyIntentClassifier->classify(
                $correlated?->replyProfileKey(),
                $normalized,
            )
            : null;

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
                'classification' => $classification,
                'purpose' => $correlated?->purpose
                    ?? $this->inboundSmsPurposeResolver->resolve($payload),
                'scope' => $correlated?->scope,
                'correlated_scheduled_message_id' => $correlated?->getKey(),
                'reply_intent_key' => $intent,
                'reply_correlation_method' => $classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY
                    ? ($correlated !== null ? 'heuristic' : 'none')
                    : null,
                'received_at' => $payload->receivedAt,
                'meta' => null,
            ],
            sender: $sender,
        );

        return $this->processInboundSmsMessageAction->handle($inboundMessage);
    }
}