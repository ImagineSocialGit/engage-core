<?php

namespace App\Services\Messaging;

use App\Data\WebinarMessageData;
use Twilio\Rest\Client;

class SmsMessagingService
{
    public function __construct(
        protected Client $twilio,
        protected DevMessageSink $devMessageSink,
        protected PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    public function sendRegistrationConfirmation(WebinarMessageData $data): void
    {
        if (! $data->leadPhone) {
            return;
        }

        $to = $this->phoneNumberNormalizer->normalize($data->leadPhone);

        if (! $to) {
            return;
        }

        $message = sprintf(
            "You're registered for %s on %s. Join here: %s",
            $data->webinarTitle,
            $data->formattedStart('M j g:i A'),
            $data->webinarJoinUrl
        );

        if (app()->environment('local')) {
            $this->devMessageSink->store('sms', [
                ...$data->toArray(),
                'kind' => 'registration_confirmation',
                'normalized_phone' => $to,
                'message' => $message,
            ]);

            return;
        }

        $this->twilio->messages->create($to, [
            'from' => config('sms.from'),
            'body' => $message,
        ]);
    }

    public function sendReminder(WebinarMessageData $data, string $messageType): void
    {
        if (! $data->leadPhone) {
            return;
        }

        $to = $this->phoneNumberNormalizer->normalize($data->leadPhone);

        if (! $to) {
            return;
        }

        $message = $this->messageForReminder($data, $messageType);

        if (! $message) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('sms', [
                ...$data->toArray(),
                'kind' => 'reminder',
                'message_type' => $messageType,
                'normalized_phone' => $to,
                'message' => $message,
            ]);

            return;
        }

        $this->twilio->messages->create($to, [
            'from' => config('services.twilio.from'),
            'body' => $message,
        ]);
    }

    public function sendPostWebinarFollowUp(WebinarMessageData $data, string $followUpType): void
    {
        if (! $data->leadPhone) {
            return;
        }

        $to = $this->phoneNumberNormalizer->normalize($data->leadPhone);

        if (! $to) {
            return;
        }

        $message = $this->messageForPostFollowUp($data, $followUpType);

        if (! $message) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('sms', [
                ...$data->toArray(),
                'kind' => 'post_webinar_follow_up',
                'follow_up_type' => $followUpType,
                'normalized_phone' => $to,
                'message' => $message,
            ]);

            return;
        }

        $this->twilio->messages->create($to, [
            'from' => config('services.twilio.from'),
            'body' => $message,
        ]);
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
