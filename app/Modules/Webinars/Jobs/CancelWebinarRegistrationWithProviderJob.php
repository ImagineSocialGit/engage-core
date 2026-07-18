<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\CancelWebinarRegistrationWithProviderAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CancelWebinarRegistrationWithProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $registrationId,
    ) {
        $this->onQueue((string) config(
            'webinars.queues.registration',
            'webinars',
        ));
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
            (new WithoutOverlapping('webinar-provider-cancellation:'.$this->registrationId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(
        CancelWebinarRegistrationWithProviderAction $cancelWithProvider,
    ): void {
        $registration = WebinarRegistration::query()
            ->with('webinar')
            ->find($this->registrationId);

        if (! $registration instanceof WebinarRegistration) {
            return;
        }

        $result = $cancelWithProvider->handle($registration);

        if ($result->shouldRetry()) {
            throw new RuntimeException(
                'Webinar registration provider cancellation is not complete.',
            );
        }
    }
}
