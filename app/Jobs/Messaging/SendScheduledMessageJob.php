<?php

namespace App\Jobs\Messaging;

use App\Actions\Campaigns\ScheduleNextCampaignStepAction;
use App\Contracts\Messaging\Email\EmailMessage;
use App\Contracts\Messaging\Sms\SmsMessage;
use App\Enums\MessageChannel;
use App\Models\CampaignEnrollment;
use App\Models\ScheduledMessage;
use App\Services\Messaging\Email\EmailMessagingService;
use App\Services\ConditionChecker;
use App\Services\Messaging\MessageEligibilityGate;
use App\Services\Messaging\Sms\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $scheduledMessageId,
    ) {}

    public function handle(
        ConditionChecker $conditionChecker,
        MessageEligibilityGate $messageEligibilityGate,
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
        ScheduleNextCampaignStepAction $scheduleNextCampaignStepAction,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->with(['contact', 'context'])
            ->find($this->scheduledMessageId);

        if (! $scheduledMessage || $scheduledMessage->status !== 'pending') {
            return;
        }

        $contact = $scheduledMessage->contact;

        if (! $contact) {
            $this->markSkipped($scheduledMessage, 'Contact not found.');

            return;
        }

        if (! $conditionChecker->passes(
            conditions: $scheduledMessage->meta['conditions'] ?? [],
            context: $this->conditionContext($scheduledMessage),
        )) {
            $this->markSkipped($scheduledMessage, 'Message conditions no longer pass.');

            return;
        }

        if (! $messageEligibilityGate->allows(
            contact: $contact,
            channel: $scheduledMessage->channel,
            purpose: $scheduledMessage->purpose,
            scope: $scheduledMessage->scope,
            messageKey: $scheduledMessage->message_type,
            definitionConfigPath: $scheduledMessage->meta['definition_config_path'] ?? null,
        )) {
            $this->markSkipped($scheduledMessage, 'Message eligibility gate denied send.');

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

            $this->scheduleNextCampaignStepIfNeeded(
                scheduledMessage: $scheduledMessage,
                scheduleNextCampaignStepAction: $scheduleNextCampaignStepAction,
            );

        } catch (Throwable $exception) {
            $scheduledMessage->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    private function scheduleNextCampaignStepIfNeeded(
        ScheduledMessage $scheduledMessage,
        ScheduleNextCampaignStepAction $scheduleNextCampaignStepAction,
    ): void {
        $campaignEnrollmentId = $scheduledMessage->meta['campaign_enrollment_id'] ?? null;

        if (! is_numeric($campaignEnrollmentId)) {
            return;
        }

        $enrollment = CampaignEnrollment::query()->find((int) $campaignEnrollmentId);

        if (! $enrollment) {
            return;
        }

        $scheduleNextCampaignStepAction->handle(
            enrollment: $enrollment,
            dispatchKey: 'marketing_message_sent',
            context: $scheduledMessage->context,
            payload: [],
            meta: [
                'previous_scheduled_message_id' => $scheduledMessage->id,
            ],
        );
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

    /**
     * @return array<string, mixed>
     */
    private function conditionContext(ScheduledMessage $scheduledMessage): array
    {
        $context = [
            'contact' => $scheduledMessage->contact?->toArray() ?? [],
        ];

        $relatedContext = $scheduledMessage->context;

        if ($relatedContext instanceof Model) {
            $context[Str::snake(class_basename($relatedContext))] = $relatedContext->toArray();
        }

        $payload = $scheduledMessage->payload ?? [];

        return array_replace_recursive(
            $context,
            is_array($payload['runtime_context'] ?? null) ? $payload['runtime_context'] : [],
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
            is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
        );
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
            'status' => 'skipped',
            'skipped_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }
}