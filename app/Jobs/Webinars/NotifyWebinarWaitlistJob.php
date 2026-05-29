<?php

namespace App\Jobs\Webinars;

use App\Actions\Webinars\DispatchWebinarWaitlistMessagesAction;
use App\Models\WebinarSeries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyWebinarWaitlistJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $seriesId,
    ) {
        $this->onQueue(config('webinars.queues.notifications'));
    }

    public function handle(DispatchWebinarWaitlistMessagesAction $dispatchWebinarWaitlistMessagesAction): void
    {
        $series = WebinarSeries::query()->find($this->seriesId);

        if (! $series) {
            return;
        }

        $dispatchWebinarWaitlistMessagesAction->handle($series);
    }
}