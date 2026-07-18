<?php

namespace App\Support\AutomationEvents\Jobs;

use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishAutomationEventOutboxEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'automation-events:outbox-publication';
    }

    public function handle(AutomationEventOutbox $outbox): void
    {
        $outbox->publishPending();
    }
}