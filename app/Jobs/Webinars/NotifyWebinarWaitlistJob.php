<?php

namespace App\Jobs\Webinars;

use App\Actions\Webinars\NotifyWebinarWaitlistAction;
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

    public function handle(NotifyWebinarWaitlistAction $notifyWebinarWaitlistAction): void
    {
        $series = WebinarSeries::query()->find($this->seriesId);

        if (! $series) {
            return;
        }

        $notifyWebinarWaitlistAction->handle($series);
    }
}