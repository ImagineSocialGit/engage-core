<?php

namespace App\Modules\Campaigns\Console\Commands;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use Illuminate\Console\Command;
use Throwable;

class SyncCampaignPresetsCommand extends Command
{
    protected $signature = 'campaigns:sync-presets {preset? : Optional preset key, such as mortgage or webinar_funnel}';

    protected $description = 'Sync campaign preset definitions into database-owned Campaign and CampaignStep records.';

    public function handle(SyncCampaignPresetsAction $syncCampaignPresets): int
    {
        $presetKey = $this->argument('preset');

        $presetKey = is_string($presetKey) && trim($presetKey) !== ''
            ? trim($presetKey)
            : null;

        try {
            $result = $syncCampaignPresets->handle($presetKey);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Campaign presets synced.');

        $this->table(
            ['Item', 'Count'],
            [
                ['Campaigns created', $result->campaignsCreated],
                ['Campaigns updated', $result->campaignsUpdated],
                ['Campaigns skipped', $result->campaignsSkipped],
                ['Steps created', $result->stepsCreated],
                ['Steps updated', $result->stepsUpdated],
                ['Steps skipped', $result->stepsSkipped],
            ],
        );

        return self::SUCCESS;
    }
}