<?php

namespace App\Actions\Webinars;

use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendEmailMessageJob;
use App\Jobs\Messaging\SendSmsMessageJob;
use App\Messaging\Payloads\Webinars\WebinarFollowUpEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarFollowUpSmsPayload;
use App\Models\WebinarRegistration;
use App\Models\WebinarScheduledMessage;
use App\Services\Messaging\MessageEligibilityGate;

class ProcessWebinarOutcomeAction
{
    public function __construct(
        private readonly MessageEligibilityGate $messageEligibilityGate,
    ) {}

    public function handle(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['lead', 'webinar']);

        if (data_get($registration->meta, 'post_webinar_routed_at')) {
            return;
        }

        if ($registration->attended_at) {
            $this->dispatchFollowUpMessages($registration, 'webinar_replay');

            return;
        }

        $this->dispatchFollowUpMessages($registration, 'webinar_missed');
    }

    protected function dispatchFollowUpMessages(
        WebinarRegistration $registration,
        string $followUpType
    ): void {
        $meta = $registration->meta ?? [];
        $meta['post_webinar_routed_at'] = now()->toIso8601String();

        $registration->forceFill([
            'meta' => $meta,
        ])->save();

        if (
            $registration->lead
            && $this->messageEligibilityGate->canSend(
                $registration->lead,
                MessageChannel::Email,
                MessagePurpose::Transactional,
            )
        ) {
            $this->dispatchEmail($registration, $followUpType);
        }

        if (
            $registration->lead
            && $this->messageEligibilityGate->canSend(
                $registration->lead,
                MessageChannel::Sms,
                MessagePurpose::Transactional,
            )
        ) {
            $this->dispatchSms($registration, $followUpType);
        }
    }

    protected function dispatchEmail(
        WebinarRegistration $registration,
        string $followUpType
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => MessageChannel::Email->value,
                'message_type' => 'post_'.$followUpType,
            ],
            [
                'status' => 'pending',
                'send_at' => now(),
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        SendEmailMessageJob::dispatch(
            payloadClass: WebinarFollowUpEmailPayload::class,
            payload: [
                ...WebinarMessageData::fromRegistration($registration)->toArray(),
                'follow_up_type' => $followUpType,
            ],
            scheduledMessageId: $scheduled->id,
        )->onQueue(config('webinars.queues.followups'));
    }

    protected function dispatchSms(
        WebinarRegistration $registration,
        string $followUpType
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => MessageChannel::Sms->value,
                'message_type' => 'post_'.$followUpType,
            ],
            [
                'status' => 'pending',
                'send_at' => now(),
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        SendSmsMessageJob::dispatch(
            payloadClass: WebinarFollowUpSmsPayload::class,
            payload: [
                ...WebinarMessageData::fromRegistration($registration)->toArray(),
                'follow_up_type' => $followUpType,
            ],
            scheduledMessageId: $scheduled->id,
        )->onQueue(config('webinars.queues.followups'));
    }
}