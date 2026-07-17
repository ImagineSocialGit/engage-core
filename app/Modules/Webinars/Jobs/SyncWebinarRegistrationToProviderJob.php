<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Data\WebinarRegistrationConsentTransition;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncWebinarRegistrationToProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param array<int, array<string, mixed>> $consentTransitions
     */
    public function __construct(
        public readonly int $registrationId,
        public readonly array $consentTransitions = [],
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
            (new WithoutOverlapping('webinar-provider-sync:'.$this->registrationId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(
        SyncWebinarRegistrationToProviderAction $syncToProvider,
        DispatchWebinarRegistrationMessagesAction $dispatchRegistrationMessages,
    ): void {
        $registration = WebinarRegistration::query()
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->find($this->registrationId);

        if (! $registration instanceof WebinarRegistration) {
            return;
        }

        $syncResult = $syncToProvider->handle($registration);

        if ($syncResult->shouldRetry()) {
            throw new RuntimeException(
                'Webinar registration provider synchronization is not complete.',
            );
        }

        $dispatchRegistrationMessages->handle(
            $registration->fresh([
                'contact',
                'webinar',
                'webinar.webinarSeries',
            ]) ?? $registration,
            null,
            $this->rehydrateConsentGrants(),
        );
    }

    /** @return array<int, MessageConsentGrantResult> */
    private function rehydrateConsentGrants(): array
    {
        $grants = [];

        foreach ($this->consentTransitions as $transitionData) {
            if (! is_array($transitionData)) {
                continue;
            }

            $grant = WebinarRegistrationConsentTransition::fromArray(
                $transitionData,
            )->toGrant();

            if ($grant instanceof MessageConsentGrantResult) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }
}
