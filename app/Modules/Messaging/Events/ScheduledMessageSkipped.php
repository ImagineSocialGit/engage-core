<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class ScheduledMessageSkipped
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

        if ($scheduledMessage->status !== ScheduledMessage::STATUS_SKIPPED
            || ! $this->terminalResult->isSkipped()
            || $this->terminalResult->scheduledMessageId !== (int) $scheduledMessage->getKey()
        ) {
            throw new InvalidArgumentException(
                'ScheduledMessageSkipped requires a matching skipped terminal result.',
            );
        }
    }
}