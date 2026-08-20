<?php

namespace App\Modules\Campaigns\Data;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;

final class CampaignEnrollmentArbitrationResult
{
    /**
     * @param array<int, array<string, int|string>> $superseded
     */
    public function __construct(
        public readonly Campaign $campaign,
        public readonly ?CampaignEnrollment $existingEnrollment = null,
        public readonly array $superseded = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function enrollmentMeta(): array
    {
        if ($this->campaign->family_key === null) {
            return [];
        }

        return [
            'campaign_family' => [
                'family_key' => $this->campaign->family_key,
                'priority' => (int) $this->campaign->priority,
                'superseded' => $this->superseded,
            ],
        ];
    }
}