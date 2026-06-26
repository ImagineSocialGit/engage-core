<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\DispatchWebinarWaitlistMessagesAction;
use App\Modules\Webinars\Models\WebinarSeries;
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

    public function handle(
        DispatchWebinarWaitlistMessagesAction $dispatchWebinarWaitlistMessagesAction
    ): void {
        $series = WebinarSeries::query()
            ->with([
                'webinars' => fn ($query) => $query
                    ->where('starts_at', '>', now())
                    ->orderBy('starts_at'),
            ])
            ->find($this->seriesId);

        if (! $series) {
            return;
        }

        $webinar = $series->webinars->first();

        if (! $webinar) {
            return;
        }

        $dispatchWebinarWaitlistMessagesAction->handle($webinar);
    }
}