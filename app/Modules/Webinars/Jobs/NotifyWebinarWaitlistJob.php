<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\DispatchWebinarWaitlistMessagesAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
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
        ResolveRegisterableWebinarAction $resolveRegisterableWebinar,
        DispatchWebinarWaitlistMessagesAction $dispatchWebinarWaitlistMessagesAction,
    ): void {
        $series = WebinarSeries::query()->find($this->seriesId);

        if (! $series) {
            return;
        }

        $webinar = $resolveRegisterableWebinar->getFutureForSeries($series);

        if (! $webinar) {
            return;
        }

        $dispatchWebinarWaitlistMessagesAction->handle($webinar);
    }
}