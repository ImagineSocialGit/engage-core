<?php

namespace App\Modules\FlowRoutes\Console\Commands;

use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Console\Command;
use Throwable;

class SyncFlowRoutePresetsCommand extends Command
{
    protected $signature = 'flow-routes:sync-presets
        {preset? : Optional preset package key}
        {--force : Overwrite customized FlowRoutes and FlowRoutePoints}';

    protected $description = 'Sync FlowRoute preset definitions into database-owned FlowRoute and FlowRoutePoint records.';

    public function handle(
        SyncFlowRoutePresetsAction $syncFlowRoutePresets,
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
            $result = $syncFlowRoutePresets->handle(
                resolved: $compositionResolver->resolve($presetKey, PresetDomain::FlowRoutes),
                force: (bool) $this->option('force'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('FlowRoute presets synced.');

        $this->table(
            ['Type', 'Created', 'Updated', 'Skipped'],
            [
                [
                    'FlowRoutes',
                    $result->created['flow_routes'] ?? 0,
                    $result->updated['flow_routes'] ?? 0,
                    $result->skipped['flow_routes'] ?? 0,
                ],
                [
                    'FlowRoutePoints',
                    $result->created['flow_route_points'] ?? 0,
                    $result->updated['flow_route_points'] ?? 0,
                    $result->skipped['flow_route_points'] ?? 0,
                ],
            ],
        );

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return $result->hasErrors()
            ? self::FAILURE
            : self::SUCCESS;
    }
}
