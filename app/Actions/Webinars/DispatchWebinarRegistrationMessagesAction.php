<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Messaging\Payloads\Webinars\WebinarConfirmationEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarConfirmationSmsPayload;
use App\Messaging\Payloads\Webinars\WebinarReminderEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarReminderSmsPayload;
use App\Models\WebinarRegistration;
use Carbon\CarbonInterface;

class DispatchWebinarRegistrationMessagesAction
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

        $payload = WebinarMessageData::fromRegistration($registration)->toArray();

        $this->dispatchConfirmationMessages($registration, $payload);

        if (! $registration->webinar) {
            return;
        }

        $this->dispatchReminderMessages($registration, $payload);
    }

    private function dispatchConfirmationMessages(WebinarRegistration $registration, array $payload): void
    {
        $this->dispatchMessage(
            registration: $registration,
            channel: MessageChannel::Email->value,
            messageType: 'webinar_registration_confirmation',
            payloadClass: WebinarConfirmationEmailPayload::class,
            payload: $payload,
            sendAt: now(),
            queue: config('webinars.queues.confirmation_messages'),
        );

        $this->dispatchMessage(
            registration: $registration,
            channel: MessageChannel::Sms->value,
            messageType: 'webinar_registration_confirmation',
            payloadClass: WebinarConfirmationSmsPayload::class,
            payload: $payload,
            sendAt: now(),
            queue: config('webinars.queues.confirmation_messages'),
        );
    }

    private function dispatchReminderMessages(WebinarRegistration $registration, array $payload): void
    {
        $config = config('reminders.webinars', []);

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        foreach ($config['schedule'] ?? [] as $reminderType) {
            $offset = $config['reminder_offsets'][$reminderType] ?? null;

            if ($offset === null) {
                continue;
            }

            $sendAt = $registration->webinar->starts_at->copy()->subMinutes($offset);

            if ($sendAt->isPast()) {
                continue;
            }

            $messageType = $this->reminderMessageType($reminderType);

            $reminderPayload = [
                ...$payload,
                'message_type' => $messageType,
                'reminder_type' => $reminderType,
            ];

            $this->dispatchMessage(
                registration: $registration,
                channel: MessageChannel::Email->value,
                messageType: $messageType,
                payloadClass: WebinarReminderEmailPayload::class,
                payload: $reminderPayload,
                sendAt: $sendAt,
                queue: config('webinars.queues.reminders'),
            );

            $this->dispatchMessage(
                registration: $registration,
                channel: MessageChannel::Sms->value,
                messageType: $messageType,
                payloadClass: WebinarReminderSmsPayload::class,
                payload: $reminderPayload,
                sendAt: $sendAt,
                queue: config('webinars.queues.reminders'),
            );
        }
    }

    private function dispatchMessage(
        WebinarRegistration $registration,
        string $channel,
        string $messageType,
        string $payloadClass,
        array $payload,
        CarbonInterface $sendAt,
        ?string $queue,
    ): void {
        $this->dispatchMessageAction->handle(
            recipient: $registration->lead,
            channel: $channel,
            messageType: $messageType,
            purpose: MessagePurpose::Transactional->value,
            payloadClass: $payloadClass,
            payload: $payload,
            sendAt: $sendAt,
            context: $registration,
            dedupeKey: $this->dedupeKey($registration, $channel, $messageType),
            meta: [
                'queue' => $queue,
            ],
        );
    }

    private function reminderMessageType(string $reminderType): string
    {
        return match ($reminderType) {
            '10_days' => 'reminder_10d',
            '7_days' => 'reminder_7d',
            '24_hours' => 'reminder_24h',
            '30_minutes' => 'reminder_30m',
            '10_minutes' => 'reminder_10m',
            default => 'webinar_reminder_'.$reminderType,
        };
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