<?php

namespace App\Modules\InboundMessaging\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ScheduledMessage;
use BackedEnum;
use Illuminate\Support\Str;

class ContactConversationShowDataProvider implements ContactShowDataProvider
{
    private const REPLY_LIMIT = 8;
    private const CONVERSATION_LIMIT = 12;

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
            ->unique('id')
            ->sortByDesc(fn (array $item): int => $item['occurred_at']?->timestamp ?? 0)
            ->take(self::CONVERSATION_LIMIT)
            ->values();

        $latestInbound = $conversation->first(
            fn (array $item): bool => $item['direction'] === 'inbound',
        );

        return [
            'conversationItems' => $conversation->all(),
            'latestInboundReply' => is_array($latestInbound)
                ? $latestInbound
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
            'direction' => 'inbound',
            'channel' => $this->enumValue($message->channel),
            'title' => $context !== null && $context !== ''
                ? 'Reply to '.$context
                : 'Inbound '.$this->label($this->enumValue($message->channel)),
            'body' => $this->cleanText($message->body),
            'intent' => $this->label($message->reply_intent_key),
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

        return [
            'id' => 'outbound-'.$message->getKey(),
            'direction' => 'outbound',
            'channel' => $message->channel,
            'title' => is_string($subject) && trim($subject) !== ''
                ? trim($subject)
                : ($this->label($message->message_type) ?? 'Outbound message'),
            'body' => is_string($messageBody)
                ? $this->cleanText($messageBody)
                : null,
            'intent' => null,
            'occurred_at' => $message->sent_at ?? $message->send_at ?? $message->updated_at,
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
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
}