<?php

namespace App\Actions\Webinars;

use App\Jobs\Messaging\SendEmailMessageJob;
use App\Jobs\Messaging\SendSmsMessageJob;
use App\Messaging\Payloads\Webinars\WebinarWaitlistScheduledEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarWaitlistScheduledSmsPayload;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;

class NotifyWebinarWaitlistAction
{
    public function __construct(
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
    ) {}

    public function handle(WebinarSeries $series): int
    {
        $webinar = $this->getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            return 0;
        }

        $notified = 0;

        WebinarWaitlistSignup::query()
            ->where('webinar_series_id', $series->id)
            ->whereNull('notified_at')
            ->orderBy('id')
            ->chunkById(100, function ($signups) use ($webinar, &$notified): void {
                foreach ($signups as $signup) {
                    if ($signup->email && $signup->email_consent_at) {
                        SendEmailMessageJob::dispatch(
                            payloadClass: WebinarWaitlistScheduledEmailPayload::class,
                            payload: [
                                'signup_id' => $signup->id,
                                'webinar_id' => $webinar->id,
                            ],
                        )->onQueue(config('webinars.queues.notifications'));
                    }

                    if ($signup->phone && $signup->sms_consent_at) {
                        SendSmsMessageJob::dispatch(
                            payloadClass: WebinarWaitlistScheduledSmsPayload::class,
                            payload: [
                                'signup_id' => $signup->id,
                                'webinar_id' => $webinar->id,
                            ],
                        )->onQueue(config('webinars.queues.notifications'));
                    }

                    $signup->forceFill([
                        'notified_at' => now(),
                    ])->save();

                    $notified++;
                }
            });

        return $notified;
    }
}