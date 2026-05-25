<?php

namespace App\Actions\Webinars;

use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendEmailMessageJob;
use App\Jobs\Messaging\SendSmsMessageJob;
use App\Messaging\Payloads\Webinars\WebinarReminderEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarReminderSmsPayload;
use App\Models\WebinarRegistration;
use App\Models\WebinarScheduledMessage;
use App\Services\Messaging\MessageEligibilityGate;

class ScheduleWebinarRemindersAction
{
    public function __construct(
        private readonly MessageEligibilityGate $messageEligibilityGate,
    ) {}

    public function handle(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['lead', 'webinar']);

        if (! $registration->webinar) {
            return;
        }

        foreach (config('webinars.reminders') as $reminder) {
            $sendAt = $registration->webinar->starts_at
                ->copy()
                ->subMinutes($reminder['minutes_before']);

            if ($sendAt->isPast()) {
                continue;
            }

            if (
                $registration->lead
                && $this->messageEligibilityGate->canSend(
                    $registration->lead,
                    MessageChannel::Email,
                    MessagePurpose::Transactional,
                )
            ) {
                $this->scheduleEmailReminder(
                    registration: $registration,
                    reminderType: $reminder['type'],
                    sendAt: $sendAt,
                );
            }

            if (
                $registration->lead
                && $this->messageEligibilityGate->canSend(
                    $registration->lead,
                    MessageChannel::Sms,
                    MessagePurpose::Transactional,
                )
            ) {
                $this->scheduleSmsReminder(
                    registration: $registration,
                    reminderType: $reminder['type'],
                    sendAt: $sendAt,
                );
            }
        }
    }

    protected function scheduleEmailReminder(
        WebinarRegistration $registration,
        string $reminderType,
        $sendAt,
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => MessageChannel::Email->value,
                'message_type' => $reminderType,
            ],
            [
                'status' => 'pending',
                'send_at' => $sendAt,
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        SendEmailMessageJob::dispatch(
            payloadClass: WebinarReminderEmailPayload::class,
            payload: [
                ...WebinarMessageData::fromRegistration($registration)->toArray(),
                'reminder_type' => $reminderType,
            ],
            scheduledMessageId: $scheduled->id,
        )
            ->delay($sendAt)
            ->onQueue(config('webinars.queues.reminders'));
    }

    protected function scheduleSmsReminder(
        WebinarRegistration $registration,
        string $reminderType,
        $sendAt,
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => MessageChannel::Sms->value,
                'message_type' => $reminderType,
            ],
            [
                'status' => 'pending',
                'send_at' => $sendAt,
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        SendSmsMessageJob::dispatch(
            payloadClass: WebinarReminderSmsPayload::class,
            payload: [
                ...WebinarMessageData::fromRegistration($registration)->toArray(),
                'reminder_type' => $reminderType,
            ],
            scheduledMessageId: $scheduled->id,
        )
            ->delay($sendAt)
            ->onQueue(config('webinars.queues.reminders'));
    }
}