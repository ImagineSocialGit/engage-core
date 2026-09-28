<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Contracts\CampaignAudienceCompletionNotifier;
use App\Modules\Campaigns\Models\Campaign;

final class UnavailableCampaignAudienceCompletionNotifier implements CampaignAudienceCompletionNotifier
{
    public function notify(Campaign $campaign, int $completedCount, int $cycle): bool
    {
        return false;
    }
}