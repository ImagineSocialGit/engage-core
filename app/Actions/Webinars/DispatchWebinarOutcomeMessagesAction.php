<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Messaging\Payloads\Webinars\WebinarFollowUpEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarFollowUpSmsPayload;
use App\Models\WebinarRegistration;

class DispatchWebinarOutcomeMessagesAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    public function handle(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['lead', 'webinar']);

        if (! $registration->lead) {
            return;
        }

        if (data_get($registration->meta, 'post_webinar_routed_at')) {
            return;
        }

        $followUpType = $registration->attended_at
            ? 'replay'
            : 'missed';

        $this->dispatchFollowUpMessages($registration, $followUpType);

        $meta = $registration->meta ?? [];
        $meta['post_webinar_routed_at'] = now()->toIso8601String();

        $registration->forceFill([
            'meta' => $meta,
        ])->save();
    }

    private function dispatchFollowUpMessages(WebinarRegistration $registration, string $followUpType): void
    {
        $payload = [
            ...WebinarMessageData::fromRegistration($registration)->toArray(),
            'follow_up_type' => $followUpType,
        ];

        $messageType = 'webinar_post_'.$followUpType;

        $this->dispatchMessage(
            registration: $registration,
            channel: MessageChannel::Email->value,
            messageType: $messageType,
            payloadClass: WebinarFollowUpEmailPayload::class,
            payload: $payload,
        );

        $this->dispatchMessage(
            registration: $registration,
            channel: MessageChannel::Sms->value,
            messageType: $messageType,
            payloadClass: WebinarFollowUpSmsPayload::class,
            payload: $payload,
        );
    }

    private function dispatchMessage(
        WebinarRegistration $registration,
        string $channel,
        string $messageType,
        string $payloadClass,
        array $payload,
    ): void {
        $this->dispatchMessageAction->handle(
            recipient: $registration->lead,
            channel: $channel,
            messageType: $messageType,
            purpose: MessagePurpose::Transactional->value,
            payloadClass: $payloadClass,
            payload: $payload,
            sendAt: now(),
            context: $registration,
            dedupeKey: $this->dedupeKey($registration, $channel, $messageType),
            meta: [
                'queue' => config('webinars.queues.followups'),
            ],
        );
    }

    private function dedupeKey(WebinarRegistration $registration, string $channel, string $messageType): string
    {
        return implode(':', [
            'scheduled-message',
            $registration->lead->getMorphClass(),
            $registration->lead->getKey(),
            $registration->getMorphClass(),
            $registration->getKey(),
            $channel,
            $messageType,
        ]);
    }
}