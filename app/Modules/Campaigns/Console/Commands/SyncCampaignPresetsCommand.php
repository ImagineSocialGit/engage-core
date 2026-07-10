<?php

namespace App\Modules\Campaigns\Console\Commands;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Console\Command;
use Throwable;

class SyncCampaignPresetsCommand extends Command
{
    protected $signature = 'campaigns:sync-presets {preset? : Optional preset package key}';

    protected $description = 'Sync campaign preset definitions into database-owned Campaign, CampaignStep, and CampaignStepVariant records. Customized records are preserved; no force mode is supported.';

    public function handle(
        SyncCampaignPresetsAction $syncCampaignPresets,
        PresetCompositionResolver $compositionResolver,
        PresetPackageResolver $packageResolver,
    ): int {
        $argumentPreset = $this->argument('preset');
        $presetKey = $packageResolver->resolvePresetKey(
            is_string($argumentPreset) ? $argumentPreset : null,
        );

        if ($presetKey === null) {
            $this->error('No preset package is configured.');

            return self::FAILURE;
        }

        try {
            $result = $syncCampaignPresets->handle(
                $compositionResolver->resolve($presetKey, PresetDomain::Campaigns),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Campaign presets synced.');
        $this->line('Force mode: not supported. Customized Campaigns, Steps, and Variants are preserved.');

        $this->table(
            ['Item', 'Count'],
            [
                ['Campaigns created', $result->campaignsCreated],
                ['Campaigns updated', $result->campaignsUpdated],
                ['Campaigns skipped', $result->campaignsSkipped],
                ['Steps created', $result->stepsCreated],
                ['Steps updated', $result->stepsUpdated],
                ['Steps skipped', $result->stepsSkipped],
                ['Variants created', $result->variantsCreated],
                ['Variants updated', $result->variantsUpdated],
                ['Variants skipped', $result->variantsSkipped],
            ],
        );

        return self::SUCCESS;
    }
}
