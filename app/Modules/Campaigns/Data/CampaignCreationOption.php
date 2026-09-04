<?php

namespace App\Modules\Campaigns\Data;

final readonly class CampaignCreationOption
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $namePlaceholder,
    ) {}
}