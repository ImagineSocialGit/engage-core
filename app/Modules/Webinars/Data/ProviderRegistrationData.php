<?php

namespace App\Modules\Webinars\Data;

class ProviderRegistrationData
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $registrantId,
        public readonly ?string $joinUrl,
        public readonly array $raw = [],
    ) {}

    public function toMeta(): array
    {
        return [
            'name' => $this->provider,
            'registrant_id' => $this->registrantId,
            'join_url' => $this->joinUrl,
            'raw' => $this->raw,
        ];
    }
}