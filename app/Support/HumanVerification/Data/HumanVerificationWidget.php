<?php

namespace App\Support\HumanVerification\Data;

final readonly class HumanVerificationWidget
{
    public function __construct(
        public string $provider,
        public string $scriptUrl,
        public string $siteKey,
        public string $action,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'script_url' => $this->scriptUrl,
            'site_key' => $this->siteKey,
            'action' => $this->action,
        ];
    }
}