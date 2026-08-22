<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use BackedEnum;
use Illuminate\Validation\ValidationException;

class SendContactConversationReplyAction
{
    private const MESSAGE_TYPE = 'conversation_reply';

    public function __construct(
        private readonly ScheduleMessageAction $scheduleMessage,
        private readonly MessageEligibilityGate $messageEligibilityGate,
    ) {}

    public function handle(
        Contact $contact,
        InboundMessage $inboundMessage,
        string $body,
        string $requestKey,
        ?string $subject = null,
    ): ScheduledMessage {
        $body = trim($body);
        $requestKey = trim($requestKey);

        if ($body === '') {
            throw ValidationException::withMessages([
                'reply_body' => 'Write a reply before sending.',
            ]);
        }

        $correlated = $inboundMessage->correlatedScheduledMessage;
        $channel = $this->enumValue($inboundMessage->channel);
        $purpose = $this->enumValue($inboundMessage->purpose)
            ?? $this->nullableString($correlated?->purpose);
        $scope = $this->nullableString($inboundMessage->scope)
            ?? $this->nullableString($correlated?->scope);

        if (! in_array($channel, MessageChannel::values(), true)) {
            throw ValidationException::withMessages([
                'reply_body' => 'This inbound message does not have a supported reply channel.',
            ]);
        }

        if ($purpose === null || $scope === null) {
            throw ValidationException::withMessages([
                'reply_body' => 'This inbound message does not have enough messaging context to send a safe CRM reply.',
            ]);
        }

        if (! $this->messageEligibilityGate->allows(
            contact: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageKey: self::MESSAGE_TYPE,
        )) {
            throw ValidationException::withMessages([
                'reply_body' => 'Messaging permissions or suppression currently block a reply on this channel.',
            ]);
        }

        $payloadClass = $channel === MessageChannel::Email->value
            ? EmailPayload::class
            : SmsPayload::class;
        $payload = $channel === MessageChannel::Email->value
            ? [
                'to' => $contact->email,
                'subject' => $this->replySubject($subject, $correlated),
                'body' => $body,
            ]
            : [
                'to' => $contact->phone,
                'message' => $body,
            ];

        return $this->scheduleMessage->handle(
            recipient: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: self::MESSAGE_TYPE,
            payloadClass: $payloadClass,
            payload: $payload,
            sendAt: now(),
            context: $contact,
            dedupeKey: implode(':', [
                'crm_contact_conversation_reply',
                $contact->getKey(),
                $inboundMessage->getKey(),
                $requestKey,
            ]),
            meta: [
                'surface' => 'crm_contact_conversation',
            ],
            queue: $channel === MessageChannel::Email->value ? 'emails' : 'sms',
            replyProfileKey: $correlated?->replyProfileKey(),
        );
    }

    private function replySubject(
        ?string $requestedSubject,
        ?ScheduledMessage $correlated,
    ): string {
        $subject = $this->nullableString($requestedSubject);

        if ($subject === null && $correlated instanceof ScheduledMessage) {
            $subject = $this->nullableString(data_get($correlated->payload, 'subject'));
        }

        $subject ??= 'Your message';

        if (! preg_match('/^\s*re\s*:/i', $subject)) {
            $subject = 'Re: '.$subject;
        }

        return mb_substr(trim($subject), 0, 998);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $this->nullableString($value);
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