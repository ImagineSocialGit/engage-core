<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

class ProcessPostWebinarEventJob extends ProcessWebinarProviderEventJob
{
    public function __construct(
        string $provider,
        string $externalWebinarId,
    ) {
        parent::__construct(
            provider: $provider,
            externalWebinarId: $externalWebinarId,
            event: 'webinar.ended',
        );
    }
}