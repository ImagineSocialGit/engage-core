<?php

namespace App\Modules\Broadcasts\Listeners;

use App\Modules\Broadcasts\Services\BroadcastScheduledMessageResultRecorder;
use App\Modules\Messaging\Events\ScheduledMessageSent;

class MarkBroadcastRecipientSent
{
    public function __construct(
        private readonly BroadcastScheduledMessageResultRecorder $resultRecorder,
    ) {}

    public function handle(ScheduledMessageSent $event): void
    {
        $this->resultRecorder->recordSent($event->scheduledMessage);
    }
}