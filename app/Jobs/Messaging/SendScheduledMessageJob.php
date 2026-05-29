<?php

namespace App\Jobs\Messaging;

use App\Contracts\Messaging\EmailMessagePayload;
use App\Contracts\Messaging\SmsMessagePayload;
use App\Enums\MessageChannel;
use App\Models\ScheduledMessage;
use App\Services\Messaging\EmailMessagingService;
use App\Services\Messaging\MessageEligibilityGate;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $scheduledMessageId,
    ) {}

    public function handle(
        MessageEligibilityGate $messageEligibilityGate,
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->with('recipient')
            ->find($this->scheduledMessageId);

        if (! $scheduledMessage) {
            return;
        }

        if ($scheduledMessage->status !== 'pending') {
            return;
        }

        $recipient = $scheduledMessage->recipient;

        if (! $recipient) {
            $this->markSkipped($scheduledMessage, 'Recipient not found.');

            return;
        }

        if (! $messageEligibilityGate->canSend(
            recipient: $recipient,
            channel: $scheduledMessage->channel,
            purpose: $scheduledMessage->purpose,
        )) {
            $this->markSkipped($scheduledMessage, 'Recipient is not eligible for this message.');

            return;
        }

        try {
            $payload = $this->resolvePayload($scheduledMessage);

            match ($scheduledMessage->channel) {
                MessageChannel::Email->value => $this->sendEmail($payload, $emailMessagingService),
                MessageChannel::Sms->value => $this->sendSms($payload, $smsMessagingService),
                default => throw new InvalidArgumentException("Unsupported message channel [{$scheduledMessage->channel}]."),
            };

            $scheduledMessage->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'failure_reason' => null,
            ])->save();
        } catch (Throwable $exception) {
            $scheduledMessage->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    private function resolvePayload(ScheduledMessage $scheduledMessage): EmailMessagePayload|SmsMessagePayload
    {
        $payloadClass = $scheduledMessage->payload_class;

        if (! is_string($payloadClass) || ! class_exists($payloadClass)) {
            throw new InvalidArgumentException('Scheduled message payload class is invalid.');
        }

        if (! method_exists($payloadClass, 'fromArray')) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must define fromArray().");
        }

        $payload = $payloadClass::fromArray($scheduledMessage->payload ?? []);

        if (! $payload instanceof EmailMessagePayload && ! $payload instanceof SmsMessagePayload) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must implement a supported message payload contract.");
        }

        return $payload;
    }

    private function sendEmail(
        EmailMessagePayload|SmsMessagePayload $payload,
        EmailMessagingService $emailMessagingService,
    ): void {
        if (! $payload instanceof EmailMessagePayload) {
            throw new InvalidArgumentException(
                'Scheduled email message resolved to a non-email payload.'
            );
        }

        $emailMessagingService->send($payload);
    }

    private function sendSms(
        EmailMessagePayload|SmsMessagePayload $payload,
        SmsMessagingService $smsMessagingService,
    ): void {
        if (! $payload instanceof SmsMessagePayload) {
            throw new InvalidArgumentException(
                'Scheduled SMS message resolved to a non-SMS payload.'
            );
        }

        $smsMessagingService->send($payload);
    }

    private function markSkipped(ScheduledMessage $scheduledMessage, string $reason): void
    {
        $scheduledMessage->forceFill([
            'status' => 'skipped',
            'skipped_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }
}