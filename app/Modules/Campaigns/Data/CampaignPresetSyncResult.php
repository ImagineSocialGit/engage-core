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
        public int $variantsCreated = 0,
        public int $variantsUpdated = 0,
        public int $variantsSkipped = 0,
    ) {}

    public function recordCampaignCreated(): void { $this->campaignsCreated++; }
    public function recordCampaignUpdated(): void { $this->campaignsUpdated++; }
    public function recordCampaignSkipped(): void { $this->campaignsSkipped++; }
    public function recordStepCreated(): void { $this->stepsCreated++; }
    public function recordStepUpdated(): void { $this->stepsUpdated++; }
    public function recordStepSkipped(): void { $this->stepsSkipped++; }
    public function recordVariantCreated(): void { $this->variantsCreated++; }
    public function recordVariantUpdated(): void { $this->variantsUpdated++; }
    public function recordVariantSkipped(): void { $this->variantsSkipped++; }

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
            'variants_created' => $this->variantsCreated,
            'variants_updated' => $this->variantsUpdated,
            'variants_skipped' => $this->variantsSkipped,
        ];
    }
}
