<?php

namespace App\Modules\Campaigns\Data;

class CampaignPresetSyncResult
{
    public function __construct(
        public int $campaignsCreated = 0,
        public int $campaignsUpdated = 0,
        public int $campaignsSkipped = 0,
        public int $stepsCreated = 0,
        public int $stepsUpdated = 0,
        public int $stepsSkipped = 0,
    ) {}

    public function recordCampaignCreated(): void
    {
        $this->campaignsCreated++;
    }

    public function recordCampaignUpdated(): void
    {
        $this->campaignsUpdated++;
    }

    public function recordCampaignSkipped(): void
    {
        $this->campaignsSkipped++;
    }

    public function recordStepCreated(): void
    {
        $this->stepsCreated++;
    }

    public function recordStepUpdated(): void
    {
        $this->stepsUpdated++;
    }

    public function recordStepSkipped(): void
    {
        $this->stepsSkipped++;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'campaigns_created' => $this->campaignsCreated,
            'campaigns_updated' => $this->campaignsUpdated,
            'campaigns_skipped' => $this->campaignsSkipped,
            'steps_created' => $this->stepsCreated,
            'steps_updated' => $this->stepsUpdated,
            'steps_skipped' => $this->stepsSkipped,
        ];
    }
}