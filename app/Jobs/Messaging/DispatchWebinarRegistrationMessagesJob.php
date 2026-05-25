<?php

namespace App\Jobs\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Messaging\Payloads\Webinars\WebinarConfirmationEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarConfirmationSmsPayload;
use App\Models\Lead;
use App\Services\Messaging\MessageEligibilityGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DispatchWebinarRegistrationMessagesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public array $payload,
    ) {}

    public function handle(MessageEligibilityGate $messageEligibilityGate): void
    {
        $lead = Lead::query()->find((int) $this->payload['lead_id']);

        if (! $lead) {
            return;
        }

        if ($messageEligibilityGate->canSend($lead, MessageChannel::Email, MessagePurpose::Transactional)) {
            SendEmailMessageJob::dispatch(
                payloadClass: WebinarConfirmationEmailPayload::class,
                payload: $this->payload,
            )->onQueue(config('webinars.queues.confirmation_messages'));
        }

        if ($messageEligibilityGate->canSend($lead, MessageChannel::Sms, MessagePurpose::Transactional)) {
            SendSmsMessageJob::dispatch(
                payloadClass: WebinarConfirmationSmsPayload::class,
                payload: $this->payload,
            )->onQueue(config('webinars.queues.confirmation_messages'));
        }
    }
}