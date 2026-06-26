<?php

namespace App\Modules\Webinars\Data;

class ProviderRecordingData
{
    public function __construct(
        public readonly ?string $playbackUrl,
        public readonly ?string $playbackPasscode = null,
        public readonly array $raw = [],
    ) {}

    public function hasPlaybackUrl(): bool
    {
        return filled($this->playbackUrl);
    }
}