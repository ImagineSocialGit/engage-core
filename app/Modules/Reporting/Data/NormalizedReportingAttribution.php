<?php

namespace App\Modules\Reporting\Data;

final readonly class NormalizedReportingAttribution
{
    /**
     * @param array<string, string> $clickIdHashes
     */
    public function __construct(
        public string $path,
        public ?string $referrerHost = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public ?string $utmContent = null,
        public ?string $utmTerm = null,
        public array $clickIdHashes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'referrer_host' => $this->referrerHost,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'utm_content' => $this->utmContent,
            'utm_term' => $this->utmTerm,
            'click_id_hashes' => $this->clickIdHashes,
        ];
    }
}