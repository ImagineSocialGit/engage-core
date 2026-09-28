<?php

namespace App\Modules\Campaigns\Contracts;

use App\Modules\Campaigns\Models\Campaign;

interface CampaignAudienceCompletionNotifier
{
    public function notify(Campaign $campaign, int $completedCount, int $cycle): bool;
}