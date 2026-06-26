<?php

namespace App\Modules\Webinars\Data;

class ProviderWebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $event,
        public readonly ?string $externalWebinarId = null,
        public readonly ?string $externalWebinarUuid = null,
        public readonly ?string $nativeEvent = null,
        public readonly array $payload = [],
    ) {}

    public function isValidationEvent(): bool
    {
        return $this->event === 'endpoint.url_validation';
    }

    public function isWebinarEnded(): bool
    {
        return $this->event === 'webinar.ended';
    }
}