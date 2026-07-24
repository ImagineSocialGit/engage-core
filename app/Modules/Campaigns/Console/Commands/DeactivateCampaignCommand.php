<?php

namespace App\Modules\Campaigns\Console\Commands;

use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Console\Command;

class DeactivateCampaignCommand extends Command
{
    protected $signature = 'campaigns:deactivate
        {campaign : Campaign key}';

    protected $description = 'Deactivate a Campaign, cancel active enrollments, and skip its pending messages.';

    public function handle(DeactivateCampaignAction $deactivateCampaign): int
    {
        $campaignKey = trim((string) $this->argument('campaign'));

        $campaign = Campaign::query()
            ->where('key', $campaignKey)
            ->first();

        if (! $campaign instanceof Campaign) {
            $this->error("Campaign [{$campaignKey}] was not found.");

            return self::FAILURE;
        }

        $result = $deactivateCampaign->handle(
            campaign: $campaign,
            source: 'artisan',
        );

        $this->info(
            $result['status_changed']
                ? "Campaign [{$campaign->key}] is inactive."
                : "Campaign [{$campaign->key}] was already unavailable for new enrollments."
        );

        $this->table(
            ['Item', 'Count'],
            [
                ['Enrollments cancelled', $result['enrollments_cancelled']],
                ['Pending messages skipped', $result['scheduled_messages_skipped']],
            ],
        );

        return self::SUCCESS;
    }
}