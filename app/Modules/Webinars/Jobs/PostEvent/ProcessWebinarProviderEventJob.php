<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

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

        $provider = $webinarProviderManager->provider($this->provider);

        $webinar = Webinar::query()
            ->where('platform', $this->provider)
            ->where('external_id', $this->externalWebinarId)
            ->first();

        if (! $webinar) {
            return;
        }

        $lock = Cache::lock($this->lockKey(), 600);

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

    private function lockKey(): string
    {
        return implode(':', [
            'webinars',
            'post_event',
            $this->provider,
            $this->externalWebinarId,
            $this->event,
        ]);
    }
}