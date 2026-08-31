<?php

namespace App\Modules\InboundMessaging\Listeners;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Events\AutomationMessageScheduled;
use App\Modules\Messaging\Models\ScheduledMessage;

class RecordInboundAutomaticMessage
{
    public function handle(AutomationMessageScheduled $event): void
    {
        if (! $event->definition->isReply()) {
            return;
        }

        $inboundMessage = $event->context->model('current_subject')
            ?? $event->context->subject;
        $scheduledMessages = array_values(array_filter(
            $event->scheduledMessages,
            static fn (mixed $message): bool =>
                $message instanceof ScheduledMessage,
        ));

        if (! $inboundMessage instanceof InboundMessage
            || count($scheduledMessages) !== 1
        ) {
            return;
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
    }
}