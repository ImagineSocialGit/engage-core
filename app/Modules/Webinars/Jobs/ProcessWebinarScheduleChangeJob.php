<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\ProcessWebinarScheduleChangeAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebinarScheduleChangeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $changeId)
    {
        $this->onQueue((string) config('webinars.queues.notifications', 'notifications'));
    }

    public function handle(ProcessWebinarScheduleChangeAction $action): void
    {
        $action->handle($this->changeId);
    }
}