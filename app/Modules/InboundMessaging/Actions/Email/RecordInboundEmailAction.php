<?php

namespace App\Modules\InboundMessaging\Actions\Email;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\ProcessInboundMessageAction;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Reply\InboundEmailReplyCorrelator;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyTextNormalizer;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class RecordInboundEmailAction
{
    public function __construct(
        private readonly RecordInboundMessageAction $recordInboundMessageAction,
        private readonly ProcessInboundMessageAction $processInboundMessageAction,
        private readonly InboundEmailReplyCorrelator $replyCorrelator,
        private readonly InboundReplyTextNormalizer $replyTextNormalizer,
        private readonly InboundReplyIntentClassifier $replyIntentClassifier,
    ) {}

    /** @param array<int, string> $toAddresses */
    public function handle(
        string $provider,
        ?string $providerEventId,
        ?string $providerMessageId,
        ?string $from,
        array $toAddresses,
        ?string $text,
        ?string $html,
        ?string $subject = null,
        ?string $messageId = null,
        Carbon|string|null $receivedAt = null,
    ): InboundMessage {
        if (blank($providerEventId) && blank($providerMessageId)) {
            throw new InvalidArgumentException(
                'Inbound email requires a provider event or message identifier.',
            );
        }

        $fromAddress = $this->emailAddress($from);
        $toAddresses = array_values(array_filter(array_map(
            fn (mixed $address): ?string => $this->emailAddress($address),
            $toAddresses,
        )));
        $sender = $this->contact($fromAddress);
        $correlated = $sender instanceof Contact
            ? $this->replyCorrelator->correlate($sender, $toAddresses)
            : null;
        $body = $this->body($text, $html);
        $normalized = $this->replyTextNormalizer->normalize($body);
        $intent = $this->replyIntentClassifier->classify(
            $correlated?->replyProfileKey(),
            $normalized,
        );

        $inboundMessage = $this->recordInboundMessageAction->handle(
            data: [
                'channel' => MessageChannel::Email->value,
                'provider' => trim($provider),
                'provider_event_id' => $providerEventId,
                'provider_message_id' => $providerMessageId,
                'provider_context_id' => null,
                'message_id' => $this->messageId($messageId),
                'from_type' => 'email',
                'from_value' => $fromAddress,
                'to_type' => 'email',
                'to_value' => $this->preferredToAddress($toAddresses, $correlated),
                'subject' => $this->subject($subject),
                'body' => $body,
                'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
                'purpose' => $correlated?->purpose,
                'scope' => $correlated?->scope,
                'correlated_scheduled_message_id' => $correlated?->getKey(),
                'reply_intent_key' => $intent,
                'reply_correlation_method' => $correlated instanceof ScheduledMessage
                    ? 'exact'
                    : 'none',
                'received_at' => $receivedAt ? Carbon::parse($receivedAt) : now(),
                'meta' => null,
            ],
            sender: $sender,
        );

        $this->processInboundMessageAction->handle($inboundMessage);

        return $inboundMessage;
    }

    private function contact(?string $email): ?Contact
    {
        if ($email === null) {
            return null;
        }

        return Contact::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    private function preferredToAddress(array $addresses, ?ScheduledMessage $correlated): ?string
    {
        if ($correlated instanceof ScheduledMessage) {
            foreach ($addresses as $address) {
                if (str_contains($address, 'reply+')) {
                    return $address;
                }
            }
        }

        return $addresses[0] ?? null;
    }

    private function emailAddress(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        $value = mb_strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? $value
            : null;
    }

    private function subject(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/[\r\n]+/u', ' ', trim($value)) ?? trim($value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value !== ''
            ? mb_substr($value, 0, 998)
            : null;
    }

    private function messageId(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > 998
            || preg_match('/[\r\n]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    private function body(?string $text, ?string $html): ?string
    {
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $plain = html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $plain = trim(preg_replace('/[ \t]+/', ' ', $plain) ?? '');

        return $plain !== '' ? $plain : null;
    }
}