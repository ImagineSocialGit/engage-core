<?php

namespace App\Modules\InternalNotifications\Listeners;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Services\InboundMessaging\InboundMessageNotificationRecipientResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use BackedEnum;
use Illuminate\Support\Str;

class ScheduleInboundMessageInternalNotification
{
    public function __construct(
        private readonly InboundMessageNotificationRecipientResolver $recipientResolver,
        private readonly ScheduleInternalNotificationAction $scheduleInternalNotification,
    ) {}

    public function handle(InboundMessageReceived $event): void
    {
        $inboundMessage = $event->inboundMessage;

        if ($this->classificationValue($inboundMessage) !== 'normal_reply') {
            return;
        }

        $recipient = $this->recipientResolver->resolve($inboundMessage);

        if ($recipient instanceof InternalNotificationRecipient) {
            $this->scheduleInternalNotification->handle(
                recipient: $recipient,
                scope: 'inbound_messages',
                messageType: 'inbound_reply',
                content: $this->content($inboundMessage),
                context: $inboundMessage,
                dedupeKey: $this->dedupeKey($inboundMessage, $recipient),
            );
        }

        $inboundMessage->markProcessed();
    }

    /**
     * @return array<string, mixed>
     */
    private function content(InboundMessage $inboundMessage): array
    {
        $contact = $this->contact($inboundMessage);
        $contactName = $this->contactName($contact);
        $sender = $inboundMessage->from_value ?: 'Unknown sender';
        $channelLabel = $this->channelLabel($inboundMessage);

        return [
            'subject' => 'New inbound '.$channelLabel.' message from '.$this->subjectSender($contactName, $sender),
            'headline' => 'New inbound message',
            'preheader' => 'A new inbound '.$channelLabel.' message was received.',
            'body' => [
                $contact
                    ? 'A contact replied through '.$channelLabel.'.'
                    : 'An inbound '.$channelLabel.' message was received, but no matching contact was found.',
            ],
            'details' => [
                'Contact' => $contactName ?: 'No matched contact',
                'Channel' => strtoupper($this->channelValue($inboundMessage)),
                'Sender' => $sender,
                'Received' => $this->receivedAt($inboundMessage),
                'Message' => $inboundMessage->body ?: '(No message body)',
            ],
            'cta' => $contact ? [
                'label' => 'View CRM Contact',
                'url' => route('crm.contacts.show', $contact),
            ] : [],
            'sms_message' => 'New inbound '.$channelLabel.' message from '.$this->subjectSender($contactName, $sender).'.',
            'meta' => [
                'inbound_message_id' => $inboundMessage->id,
                'sender_type' => $inboundMessage->sender_type,
                'sender_id' => $inboundMessage->sender_id,
            ],
        ];
    }

    private function contact(InboundMessage $inboundMessage): ?Contact
    {
        $sender = $inboundMessage->sender;

        return $sender instanceof Contact ? $sender : null;
    }

    private function contactName(?Contact $contact): ?string
    {
        if (! $contact) {
            return null;
        }

        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== '' ? $name : $contact->email;
    }

    private function classificationValue(InboundMessage $inboundMessage): ?string
    {
        $classification = $inboundMessage->classification;

        if ($classification instanceof BackedEnum) {
            return (string) $classification->value;
        }

        return is_string($classification) ? $classification : null;
    }

    private function channelLabel(InboundMessage $inboundMessage): string
    {
        return Str::of($this->channelValue($inboundMessage))
            ->lower()
            ->headline()
            ->toString();
    }

    private function channelValue(InboundMessage $inboundMessage): string
    {
        $channel = $inboundMessage->channel;

        if ($channel instanceof BackedEnum) {
            return (string) $channel->value;
        }

        return (string) $channel;
    }

    private function subjectSender(?string $contactName, string $sender): string
    {
        return $contactName ?: $sender;
    }

    private function receivedAt(InboundMessage $inboundMessage): string
    {
        return $inboundMessage->received_at
            ? $inboundMessage->received_at
                ->timezone(config('app.timezone'))
                ->format('M j, Y g:i A T')
            : 'Unknown';
    }

    private function dedupeKey(
        InboundMessage $inboundMessage,
        InternalNotificationRecipient $recipient,
    ): string {
        return implode(':', [
            'internal_notification',
            'inbound_reply',
            $recipient->source->getMorphClass(),
            $recipient->source->getKey(),
            $inboundMessage->getMorphClass(),
            $inboundMessage->getKey(),
        ]);
    }
}