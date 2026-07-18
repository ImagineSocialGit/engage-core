<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishScheduledMessageOutboxEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'messaging:scheduled-message-outbox-publication';
    }

    public function handle(ScheduledMessageEventOutbox $eventOutbox): void
    {
        $eventOutbox->publishPending();
    }
}