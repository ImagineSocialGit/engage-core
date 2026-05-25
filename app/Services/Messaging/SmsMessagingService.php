<?php

namespace App\Services\Messaging;

use App\Data\WebinarMessageData;
use App\Models\Webinar;
use App\Models\WebinarWaitlistSignup;
use Twilio\Rest\Client;

class SmsMessagingService
{
    public function __construct(
        private readonly Client $twilio,
        private readonly DevMessageSink $devMessageSink,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly SmsSendGuard $smsSendGuard,
    ) {}

    public function sendRegistrationConfirmation(WebinarMessageData $data): void
    {
        $this->send(
            data: $data,
            kind: 'registration_confirmation',
            message: sprintf(
                "You're registered for %s on %s. Join here: %s",
                $data->webinarTitle,
                $data->formattedStart('M j g:i A'),
                $data->webinarJoinUrl
            )
        );
    }

    public function sendReminder(WebinarMessageData $data, string $messageType): void
    {
        $message = $this->messageForReminder($data, $messageType);

        if (! $message) {
            return;
        }

        $this->send(
            data: $data,
            kind: 'reminder',
            message: $message,
            metadata: [
                'message_type' => $messageType,
            ]
        );
    }

    public function sendPostWebinarFollowUp(WebinarMessageData $data, string $followUpType): void
    {
        $message = $this->messageForPostFollowUp($data, $followUpType);

        if (! $message) {
            return;
        }

        $this->send(
            data: $data,
            kind: 'post_webinar_follow_up',
            message: $message,
            metadata: [
                'follow_up_type' => $followUpType,
            ]
        );
    }

    public function sendWebinarWaitlistScheduledNotification(
        WebinarWaitlistSignup $signup,
        Webinar $webinar,
    ): void {
        if (! $signup->phone) {
            return;
        }

        $message = sprintf(
            'A new webinar has been scheduled for %s. Register here: %s',
            $webinar->title,
            $webinar->registration_url
        );

        $data = WebinarMessageData::fromArray([
            'lead_phone' => $signup->phone,
            'lead_email' => $signup->email,
            'webinar_title' => $webinar->title,
            'webinar_join_url' => $webinar->join_url,
            'webinar_registration_url' => $webinar->registration_url,
            'starts_at' => $webinar->starts_at,
            'timezone' => $webinar->timezone,
        ]);

        $this->send(
            data: $data,
            kind: 'webinar_waitlist_scheduled',
            message: $message,
        );
    }

    private function send(
        WebinarMessageData $data,
        string $kind,
        string $message,
        array $metadata = []
    ): void {
        if (! config('sms.enabled')) {
            return;
        }

        if (! $data->leadPhone) {
            return;
        }

        $to = $this->phoneNumberNormalizer->normalize($data->leadPhone);

        if (! $to) {
            return;
        }

        if (! $this->smsSendGuard->allows($data, $to, $message, $kind)) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('sms', [
                ...$data->toArray(),
                ...$metadata,
                'kind' => $kind,
                'normalized_phone' => $to,
                'message' => $message,
            ]);

            $this->smsSendGuard->record($data, $to, $message, $kind);

            return;
        }

        $this->twilio->messages->create($to, [
            'from' => config('sms.from'),
            'body' => $message,
        ]);

        $this->smsSendGuard->record($data, $to, $message, $kind);
    }

    protected function messageForReminder(WebinarMessageData $data, string $messageType): ?string
    {
        return match ($messageType) {
            'reminder_10d' => sprintf(
                '%s is 10 days away: %s on %s. Join here: %s',
                $data->webinarTitle,
                $data->webinarTitle,
                $data->formattedStart('M j g:i A'),
                $data->webinarJoinUrl
            ),
            'reminder_7d' => sprintf(
                '%s is 1 week away. It starts %s. Join here: %s',
                $data->webinarTitle,
                $data->formattedStart('M j g:i A'),
                $data->webinarJoinUrl
            ),
            'reminder_24h' => sprintf(
                'Reminder: %s is tomorrow at %s. Join here: %s',
                $data->webinarTitle,
                $data->formattedStart('M j g:i A'),
                $data->webinarJoinUrl
            ),
            'reminder_30m' => sprintf(
                '%s starts in 30 minutes at %s. Join here: %s',
                $data->webinarTitle,
                $data->formattedStart('g:i A'),
                $data->webinarJoinUrl
            ),
            'reminder_10m' => sprintf(
                '%s starts in 10 minutes. Join here: %s',
                $data->webinarTitle,
                $data->webinarJoinUrl
            ),
            'late_joiner_5m' => sprintf(
                '%s is live now. Join here: %s',
                $data->webinarTitle,
                $data->webinarJoinUrl
            ),
            default => null,
        };
    }

    protected function messageForPostFollowUp(WebinarMessageData $data, string $followUpType): ?string
    {
        return match ($followUpType) {
            'missed' => sprintf(
                "Sorry we missed you for %s. We'll follow up with next steps soon.",
                $data->webinarTitle
            ),
            'replay' => sprintf(
                "Thanks for joining %s. We'll send your replay and next steps soon.",
                $data->webinarTitle
            ),
            default => null,
        };
    }
}