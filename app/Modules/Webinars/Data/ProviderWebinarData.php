<?php

namespace App\Modules\Webinars\Data;

use Carbon\CarbonInterface;

class ProviderWebinarData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $joinUrl,
        public readonly ?string $registrationUrl,
        public readonly ?CarbonInterface $startsAt,
        public readonly ?CarbonInterface $endsAt,
        public readonly string $timezone,
        public readonly ?string $description,
        public readonly array $meta = [],
    ) {}
}