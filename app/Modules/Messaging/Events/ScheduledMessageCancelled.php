<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class ScheduledMessageCancelled
{
    use Dispatchable;
    use SerializesModels;

    public readonly ScheduledMessageTerminalResult $terminalResult;

    public function __construct(
        public readonly ScheduledMessage $scheduledMessage,
        ?ScheduledMessageTerminalResult $terminalResult = null,
    ) {
        $this->terminalResult = $terminalResult
            ?? ScheduledMessageTerminalResult::fromScheduledMessage($scheduledMessage);

        if ($scheduledMessage->status !== ScheduledMessage::STATUS_CANCELLED
            || ! $this->terminalResult->isCancelled()
            || $this->terminalResult->scheduledMessageId !== (int) $scheduledMessage->getKey()
        ) {
            throw new InvalidArgumentException(
                'ScheduledMessageCancelled requires a matching cancelled terminal result.',
            );
        }
    }
}