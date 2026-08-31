<?php

namespace App\Modules\InboundMessaging\Automation;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Automation\SendMessageAutomationActionHandler;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;

class MarkInboundMessageAutoRespondedActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly SendMessageAutomationActionHandler $sendMessage,
    ) {}

    public function key(): string
    {
        return 'inbound_messaging.automatic_message';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $inboundMessage = $context->model('current_subject')
            ?? $context->subject;

        if (! $inboundMessage instanceof InboundMessage) {
            return AutomationActionResult::failed(
                'inbound_message_subject_not_found',
            );
        }

        $sendResult = $this->sendMessage->handle($context);

        if ($sendResult->status !== AutomationActionResult::STATUS_COMPLETED) {
            return $sendResult;
        }

        $scheduledMessages = array_values(array_filter(
            $sendResult->artifacts,
            static fn (mixed $artifact): bool =>
                $artifact instanceof ScheduledMessage,
        ));
        if (count($scheduledMessages) !== 1) {
            return AutomationActionResult::failed(
                'inbound_automatic_message_requires_one_scheduled_message',
                output: $sendResult->output,
                meta: $sendResult->meta,
            );
        }

        $scheduledMessage = $scheduledMessages[0];

        $handledAt = $inboundMessage->automated_handled_at ?? now();

        $inboundMessage->forceFill([
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => $inboundMessage->completed_at ?? $handledAt,
            'automated_response_scheduled_message_id' =>
                $scheduledMessage->getKey(),
            'automated_handled_at' => $handledAt,
        ])->save();

        return AutomationActionResult::completed(
            reason: 'inbound_automatic_message_scheduled',
            artifacts: $sendResult->artifacts,
            correlationKey: $sendResult->correlationKey,
            correlationType: $sendResult->correlationType,
            correlation: $sendResult->correlation,
            output: array_replace_recursive($sendResult->output, [
                'inbound_message' => [
                    'id' => $inboundMessage->getKey(),
                    'inbox_status' => $inboundMessage->inbox_status,
                    'automated_response_scheduled_message_id' =>
                        $scheduledMessage->getKey(),
                    'automated_handled_at' => $handledAt->toISOString(),
                ],
            ]),
            meta: $sendResult->meta,
        );
    }
}