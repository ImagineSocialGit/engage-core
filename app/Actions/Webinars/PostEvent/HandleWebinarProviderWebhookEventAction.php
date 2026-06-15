<?php

namespace App\Actions\Webinars\PostEvent;

use App\Data\Webinars\ProviderWebhookEvent;
use App\Jobs\Webinars\PostEvent\ProcessWebinarProviderEventJob;

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