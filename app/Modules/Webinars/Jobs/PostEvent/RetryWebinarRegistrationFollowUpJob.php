<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RetryWebinarRegistrationFollowUpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $registrationId,
    ) {
        $this->onQueue((string) config(
            'webinars.queues.followups',
            'notifications',
        ));
    }

    public function uniqueId(): string
    {
        return 'webinar-registration-follow-up:'.$this->registrationId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(
        DispatchPostWebinarFollowUpsAction $dispatchFollowUps,
    ): void {
        $registration = WebinarRegistration::query()
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->find($this->registrationId);

        if (! $registration instanceof WebinarRegistration) {
            return;
        }

        $result = $dispatchFollowUps->executeForRegistration($registration);

        if ($result->shouldRetry()) {
            throw new RuntimeException(
                'Webinar registration follow-up planning is not complete.',
            );
        }

        if ($registration->webinar) {
            $dispatchFollowUps->refreshWebinarFollowUpCompletion(
                $registration->webinar,
            );
        }
    }
}