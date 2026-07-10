<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Console\Command;
use Throwable;

class SyncContactStatusPresetsCommand extends Command
{
    protected $signature = 'contact-statuses:sync-presets
        {preset? : Optional preset package key}
        {--force : Overwrite customized ContactStatus records with preset definitions}';

    protected $description = 'Sync contact status preset definitions into database-owned ContactStatus records.';

    public function handle(
        SyncContactStatusPresetsAction $syncContactStatusPresets,
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
            $result = $syncContactStatusPresets->handle(
                resolved: $compositionResolver->resolve($presetKey, PresetDomain::ContactStatuses),
                force: (bool) $this->option('force'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Contact status presets synced.');

        $this->table(
            ['Item', 'Count'],
            [
                ['Created', $result['created']],
                ['Updated', $result['updated']],
                ['Skipped', $result['skipped']],
            ],
        );

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }
}
