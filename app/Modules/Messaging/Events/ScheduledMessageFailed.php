<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class ScheduledMessageFailed
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

        if ($scheduledMessage->status !== ScheduledMessage::STATUS_FAILED
            || ! $this->terminalResult->isFailed()
            || $this->terminalResult->scheduledMessageId !== (int) $scheduledMessage->getKey()
        ) {
            throw new InvalidArgumentException(
                'ScheduledMessageFailed requires a matching failed terminal result.',
            );
        }
    }
}