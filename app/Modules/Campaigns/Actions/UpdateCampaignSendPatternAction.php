<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignSendPatternService;

final class UpdateCampaignSendPatternAction
{
    public function __construct(
        private readonly CampaignSendPatternService $patterns,
    ) {}

    /** @param array<string, mixed> $pattern */
    public function handle(Campaign $campaign, array $pattern): Campaign
    {
        $campaign->forceFill([
            'send_pattern' => $this->patterns->forPersistence($pattern),
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return $campaign->refresh();
    }
}