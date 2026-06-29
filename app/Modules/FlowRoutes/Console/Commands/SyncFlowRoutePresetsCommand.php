<?php

namespace App\Modules\FlowRoutes\Console\Commands;

use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use Illuminate\Console\Command;
use Throwable;

class SyncFlowRoutePresetsCommand extends Command
{
    protected $signature = 'flow-routes:sync-presets
        {preset? : Optional preset key, such as mortgage or webinar_funnel}
        {--force : Overwrite customized FlowRoutes, Points, and FlowRoutePoints}';

    protected $description = 'Sync FlowRoute preset definitions into database-owned FlowRoute, Point, and FlowRoutePoint records.';

    public function handle(SyncFlowRoutePresetsAction $syncFlowRoutePresets): int
    {
        $presetKey = $this->argument('preset');

        $presetKey = is_string($presetKey) && trim($presetKey) !== ''
            ? trim($presetKey)
            : null;

        try {
            $result = $syncFlowRoutePresets->handle(
                presetKey: $presetKey,
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
                    'Points',
                    $result->created['points'] ?? 0,
                    $result->updated['points'] ?? 0,
                    $result->skipped['points'] ?? 0,
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