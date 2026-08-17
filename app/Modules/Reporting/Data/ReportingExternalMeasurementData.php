<?php

namespace App\Modules\Reporting\Data;

use DateTimeInterface;

final readonly class ReportingExternalMeasurementData
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public DateTimeInterface $periodStart,
        public DateTimeInterface $periodEnd,
        public string $platform,
        public string $source,
        public ?string $accountId = null,
        public ?string $accountTimezone = null,
        public ?string $campaignId = null,
        public ?string $groupId = null,
        public ?string $creativeId = null,
        public ?string $campaignName = null,
        public ?string $groupName = null,
        public ?string $creativeName = null,
        public ?string $placement = null,
        public ?string $currency = null,
        public ?int $impressions = null,
        public ?int $reach = null,
        public ?int $linkClicks = null,
        public ?int $outboundClicks = null,
        public ?int $landingPageViews = null,
        public int|float|string|null $spend = null,
        public ?string $resultType = null,
        public int|float|string|null $results = null,
        public ?string $sourceFileHash = null,
        public array $meta = [],
    ) {}
}