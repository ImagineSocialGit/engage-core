<?php

namespace App\Modules\InboundMessaging\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use BackedEnum;
use Illuminate\Support\Str;

class ContactConversationShowDataProvider implements ContactShowDataProvider
{
    private const REPLY_LIMIT = 8;
    private const MANUAL_REPLY_LIMIT = 8;
    private const CONVERSATION_LIMIT = 12;
    private const CONVERSATION_REPLY_TYPE = 'conversation_reply';

    public function __construct(
        private readonly MessageEligibilityGate $messageEligibilityGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contactTypes = array_values(array_unique([
            Contact::class,
            $contact->getMorphClass(),
        ]));

        $replies = InboundMessage::query()
            ->with('correlatedScheduledMessage')
            ->whereIn('sender_type', $contactTypes)
            ->where('sender_id', $contact->getKey())
            ->where('classification', InboundMessage::CLASSIFICATION_NORMAL_REPLY)
            ->latest('received_at')
            ->latest('id')
            ->limit(self::REPLY_LIMIT)
            ->get();

        $manualReplies = ScheduledMessage::query()
            ->whereIn('recipient_type', $contactTypes)
            ->where('recipient_id', $contact->getKey())
            ->where('message_type', self::CONVERSATION_REPLY_TYPE)
            ->latest('send_at')
            ->latest('id')
            ->limit(self::MANUAL_REPLY_LIMIT)
            ->get();

        $conversation = $replies
            ->flatMap(function (InboundMessage $message): array {
                $items = [];
                $correlated = $message->correlatedScheduledMessage;

                if ($correlated instanceof ScheduledMessage) {
                    $items[] = $this->outboundItem($correlated);
                }

                $items[] = $this->inboundItem($message);

                return $items;
            })
            ->concat($manualReplies->map(
                fn (ScheduledMessage $message): array => $this->outboundItem($message),
            ))
            ->unique('id')
            ->sortByDesc(fn (array $item): int => $item['occurred_at']?->timestamp ?? 0)
            ->take(self::CONVERSATION_LIMIT)
            ->values();

        $latestInboundMessage = $replies->first();
        $latestInbound = $latestInboundMessage instanceof InboundMessage
            ? $this->inboundItem($latestInboundMessage)
            : null;

        return [
            'conversationItems' => $conversation->all(),
            'latestInboundReply' => $latestInbound,
            'conversationReply' => $latestInboundMessage instanceof InboundMessage
                ? $this->replyContext($contact, $latestInboundMessage)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundItem(InboundMessage $message): array
    {
        $correlated = $message->correlatedScheduledMessage;
        $context = $correlated instanceof ScheduledMessage
            ? trim(implode(' · ', array_filter([
                $this->label($correlated->scope),
                $this->label($correlated->message_type),
            ])))
            : null;

        return [
            'id' => 'inbound-'.$message->getKey(),
            'source_id' => (int) $message->getKey(),
            'direction' => 'inbound',
            'channel' => $this->enumValue($message->channel),
            'title' => $context !== null && $context !== ''
                ? 'Reply to '.$context
                : 'Inbound '.$this->label($this->enumValue($message->channel)),
            'body' => $this->cleanText($message->body),
            'intent' => $this->label($message->reply_intent_key),
            'status' => null,
            'occurred_at' => $message->received_at ?? $message->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundItem(ScheduledMessage $message): array
    {
        $subject = data_get($message->payload, 'subject');
        $messageBody = data_get($message->payload, 'message');

        if ($message->message_type === self::CONVERSATION_REPLY_TYPE
            && ! is_string($messageBody)
        ) {
            $messageBody = data_get($message->payload, 'body');
        }

        return [
            'id' => 'outbound-'.$message->getKey(),
            'source_id' => (int) $message->getKey(),
            'direction' => 'outbound',
            'channel' => $message->channel,
            'title' => is_string($subject) && trim($subject) !== ''
                ? trim($subject)
                : ($this->label($message->message_type) ?? 'Outbound message'),
            'body' => is_string($messageBody)
                ? $this->cleanText($messageBody)
                : null,
            'intent' => null,
            'status' => $message->status,
            'occurred_at' => $message->sent_at ?? $message->send_at ?? $message->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replyContext(
        Contact $contact,
        InboundMessage $message,
    ): array {
        $correlated = $message->correlatedScheduledMessage;
        $channel = $this->enumValue($message->channel);
        $purpose = $this->enumValue($message->purpose)
            ?? $this->nullableString($correlated?->purpose);
        $scope = $this->nullableString($message->scope)
            ?? $this->nullableString($correlated?->scope);
        $destination = match ($channel) {
            MessageChannel::Email->value => $this->nullableString($contact->email),
            MessageChannel::Sms->value => $this->nullableString($contact->phone),
            default => null,
        };

        $unavailableReason = null;

        if ($destination === null) {
            $unavailableReason = $channel === MessageChannel::Sms->value
                ? 'Add a phone number before replying by SMS.'
                : 'Add an email address before replying by email.';
        } elseif ($purpose === null || $scope === null) {
            $unavailableReason = 'This inbound message is not tied to enough messaging context to infer a safe reply permission.';
        } elseif (! $this->messageEligibilityGate->allows(
            contact: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageKey: self::CONVERSATION_REPLY_TYPE,
        )) {
            $unavailableReason = 'Messaging permissions or suppression currently block a reply on this channel.';
        }

        return [
            'inbound_message_id' => (int) $message->getKey(),
            'channel' => $channel,
            'channel_label' => $channel === MessageChannel::Sms->value ? 'SMS' : 'Email',
            'purpose' => $purpose,
            'scope' => $scope,
            'destination' => $destination,
            'subject' => $channel === MessageChannel::Email->value
                ? $this->replySubject($correlated)
                : null,
            'can_send' => $unavailableReason === null,
            'unavailable_reason' => $unavailableReason,
        ];
    }

    private function replySubject(?ScheduledMessage $correlated): string
    {
        $subject = $this->nullableString(data_get($correlated?->payload, 'subject'))
            ?? 'Your message';

        return preg_match('/^\s*re\s*:/i', $subject)
            ? trim($subject)
            : 'Re: '.trim($subject);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $this->nullableString($value);
    }

    private function label(?string $value): ?string
    {
        return filled($value)
            ? Str::of($value)->replace('_', ' ')->title()->toString()
            : null;
    }

    private function cleanText(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 600);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}