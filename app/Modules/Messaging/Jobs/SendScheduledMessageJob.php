<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<string, mixed> $horizon
     */
    public function __construct(
        public int $scheduledMessageId,
        public array $horizon = [],
    ) {}

    public function handle(
        ScheduledMessageGate $scheduledMessageGate,
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->with(['recipient', 'context'])
            ->find($this->scheduledMessageId);

        if (! $scheduledMessage || $scheduledMessage->status !== ScheduledMessage::STATUS_PENDING) {
            return;
        }

        if ($denialReason = $scheduledMessageGate->denialReason($scheduledMessage)) {
            $this->markSkipped($scheduledMessage, $denialReason);

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
                'status' => ScheduledMessage::STATUS_SENT,
                'sent_at' => now(),
                'failure_reason' => null,
            ])->save();

            ScheduledMessageSent::dispatch($scheduledMessage);
        } catch (Throwable $exception) {
            $scheduledMessage->forceFill([
                'status' => ScheduledMessage::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ])->save();

            ScheduledMessageFailed::dispatch($scheduledMessage);

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_values(array_filter([
            'scheduled-message:'.$this->scheduledMessageId,
            isset($this->horizon['recipient_type'], $this->horizon['recipient_id'])
                ? 'recipient:'.$this->horizon['recipient_type'].':'.$this->horizon['recipient_id']
                : null,
            isset($this->horizon['channel']) ? 'channel:'.$this->horizon['channel'] : null,
            isset($this->horizon['purpose']) ? 'purpose:'.$this->horizon['purpose'] : null,
            isset($this->horizon['scope']) ? 'scope:'.$this->horizon['scope'] : null,
            isset($this->horizon['message_type']) ? 'message-type:'.$this->horizon['message_type'] : null,
            isset($this->horizon['queue']) ? 'queue:'.$this->horizon['queue'] : null,
        ]));
    }

    private function resolvePayload(ScheduledMessage $scheduledMessage): EmailMessage|SmsMessage
    {
        $payloadClass = $scheduledMessage->payload_class;

        if (! is_string($payloadClass) || ! class_exists($payloadClass)) {
            throw new InvalidArgumentException('Scheduled message payload class is invalid.');
        }

        if (! method_exists($payloadClass, 'fromArray')) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must define fromArray().");
        }

        $payload = $payloadClass::fromArray(array_replace_recursive(
            [
                'channel' => $scheduledMessage->channel,
                'purpose' => $scheduledMessage->purpose,
                'scope' => $scheduledMessage->scope,
                'message_type' => $scheduledMessage->message_type,
            ],
            $scheduledMessage->payload ?? [],
        ));

        if (! $payload instanceof EmailMessage && ! $payload instanceof SmsMessage) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must implement a supported message payload contract.");
        }

        return $payload;
    }

    private function sendEmail(
        EmailMessage|SmsMessage $payload,
        EmailMessagingService $emailMessagingService,
    ): void {
        if (! $payload instanceof EmailMessage) {
            throw new InvalidArgumentException('Scheduled email message resolved to a non-email payload.');
        }

        $emailMessagingService->send($payload);
    }

    private function sendSms(
        EmailMessage|SmsMessage $payload,
        SmsMessagingService $smsMessagingService,
    ): void {
        if (! $payload instanceof SmsMessage) {
            throw new InvalidArgumentException('Scheduled SMS message resolved to a non-SMS payload.');
        }

        $smsMessagingService->send($payload);
    }

    private function markSkipped(ScheduledMessage $scheduledMessage, string $reason): void
    {
        $scheduledMessage->forceFill([
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => $reason,
            'failure_reason' => null,
        ])->save();

        ScheduledMessageSkipped::dispatch($scheduledMessage);
    }
}