<?php

namespace App\Modules\Broadcasts\Listeners;

use App\Modules\Broadcasts\Services\BroadcastScheduledMessageResultRecorder;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;

class MarkBroadcastRecipientSkipped
{
    public function __construct(
        private readonly BroadcastScheduledMessageResultRecorder $resultRecorder,
    ) {}

    public function handle(ScheduledMessageSkipped $event): void
    {
        $this->resultRecorder->recordSkipped($event->scheduledMessage);
    }
}