<?php

namespace App\Services\Messaging;

use App\Data\WebinarMessageData;
use App\Mail\WebinarRegistrationConfirmationMail;
use App\Mail\WebinarReminderMail;
use App\Mail\WebinarPostFollowUpMail;
use Illuminate\Support\Facades\Mail;

class EmailMessagingService
{
    public function __construct(
        protected DevMessageSink $devMessageSink,
    ) {}

    public function sendRegistrationConfirmation(WebinarMessageData $data): void
    {
        if (! $data->leadEmail) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', [
                ...$data->toArray(),
                'kind' => 'registration_confirmation',
            ]);
            return;
        }

        Mail::to($data->leadEmail)
            ->send(new WebinarRegistrationConfirmationMail($data));
    }

    public function sendReminder(WebinarMessageData $data, string $messageType): void
    {
        if (! $data->leadEmail) {
            return;
        }

        $subject = $this->subjectForReminder($data, $messageType);

        if (! $subject) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', [
                ...$data->toArray(),
                'kind' => 'reminder',
                'message_type' => $messageType,
                'subject' => $subject,
            ]);
            return;
        }

        Mail::to($data->leadEmail)
            ->send(new WebinarReminderMail($data, $messageType, $subject));
    }

    public function sendPostWebinarFollowUp(WebinarMessageData $data, string $followUpType): void
    {
        if (! $data->leadEmail) {
            return;
        }

        $subject = $this->subjectForPostFollowUp($data, $followUpType);

        if (! $subject) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', [
                ...$data->toArray(),
                'kind' => 'post_webinar_follow_up',
                'follow_up_type' => $followUpType,
                'subject' => $subject,
            ]);
            return;
        }

        Mail::to($data->leadEmail)
            ->send(new WebinarPostFollowUpMail($data, $followUpType, $subject));
    }

    protected function subjectForReminder(WebinarMessageData $data, string $messageType): ?string
    {
        return match ($messageType) {
            'reminder_10d' => '10 days until ' . $data->webinarTitle,
            'reminder_7d' => '1 week until ' . $data->webinarTitle,
            'reminder_24h' => 'Tomorrow: ' . $data->webinarTitle,
            'reminder_30m' => 'Starting soon: ' . $data->webinarTitle,
            'reminder_10m' => 'Starts in 10 minutes: ' . $data->webinarTitle,
            'late_joiner_5m' => 'We are live: ' . $data->webinarTitle,
            default => null,
        };
    }

    protected function subjectForPostFollowUp(WebinarMessageData $data, string $followUpType): ?string
    {
        return match ($followUpType) {
            'missed' => 'Sorry we missed you: ' . $data->webinarTitle,
            'replay' => 'Thanks for joining: ' . $data->webinarTitle,
            default => null,
        };
    }
}