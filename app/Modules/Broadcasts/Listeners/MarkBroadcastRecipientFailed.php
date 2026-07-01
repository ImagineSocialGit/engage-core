<?php

namespace App\Modules\Broadcasts\Listeners;

use App\Modules\Broadcasts\Services\BroadcastScheduledMessageResultRecorder;
use App\Modules\Messaging\Events\ScheduledMessageFailed;

class MarkBroadcastRecipientFailed
{
    public function __construct(
        private readonly BroadcastScheduledMessageResultRecorder $resultRecorder,
    ) {}

    public function handle(ScheduledMessageFailed $event): void
    {
        $this->resultRecorder->recordFailed($event->scheduledMessage);
    }
}