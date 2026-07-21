<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarProviderEventTargetAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessWebinarProviderEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public function __construct(
        public string $provider,
        public string $externalWebinarId,
        public string $event,
        public ?string $providerEventType = null,
        public ?string $externalWebinarUuid = null,
    ) {}

    public function handle(WebinarProviderManager $webinarProviderManager): void
    {
        $events = config('webinars.post_event.events', []);

        if (! is_array($events)) {
            return;
        }

        $actionClasses = $events[$this->event] ?? [];

        if (! is_array($actionClasses) || $actionClasses === []) {
            return;
        }

        $webinar = app(ResolveWebinarProviderEventTargetAction::class)->execute(
            provider: $this->provider,
            externalWebinarId: $this->externalWebinarId,
            providerEventType: $this->providerEventType,
            externalWebinarUuid: $this->externalWebinarUuid,
        );

        if (! $webinar instanceof Webinar) {
            return;
        }

        $provider = $webinarProviderManager->forWebinar($webinar);
        $lock = Cache::lock($this->lockKey($webinar), 600);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            foreach ($actionClasses as $actionClass) {
                if (! is_string($actionClass) || $actionClass === '') {
                    continue;
                }

                $result = app($actionClass)->execute(
                    provider: $provider,
                    webinar: $webinar,
                    event: $this->event,
                );

                if ($result === false) {
                    $this->release((int) config('webinars.post_event.retry_seconds', 300));

                    return;
                }

                $webinar->refresh();
            }
        } finally {
            $lock->release();
        }
    }

    public function backoff(): array
    {
        return [300, 300, 600, 600, 900, 900, 1800];
    }

    private function lockKey(Webinar $webinar): string
    {
        return implode(':', [
            'webinars',
            'post_event',
            $webinar->providerKey(),
            $webinar->providerEventTypeKey(),
            $webinar->external_id,
            $this->event,
        ]);
    }
}