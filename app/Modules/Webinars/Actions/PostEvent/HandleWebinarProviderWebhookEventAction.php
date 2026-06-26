<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarProviderEventJob;

class HandleWebinarProviderWebhookEventAction
{
    public function execute(ProviderWebhookEvent $event): void
    {
        if (! $event->externalWebinarId) {
            return;
        }

        ProcessWebinarProviderEventJob::dispatch(
            provider: $event->provider,
            externalWebinarId: $event->externalWebinarId,
            event: $event->event,
        )->onQueue('post_event');
    }
}