<?php

namespace App\Console\Commands;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use Illuminate\Console\Command;
use Throwable;

class SyncPresetsCommand extends Command
{
    protected $signature = 'presets:sync
        {preset? : Optional preset key, such as mortgage or webinar_funnel}
        {--force-flow-routes : Overwrite customized FlowRoutes, Points, and FlowRoutePoints}';

    protected $description = 'Sync preset-owned database definitions in dependency-safe order.';

    public function handle(
        SyncContactStatusPresetsAction $syncContactStatusPresets,
        SyncTaskPresetsAction $syncTaskPresets,
        SyncCampaignPresetsAction $syncCampaignPresets,
        SyncFlowRoutePresetsAction $syncFlowRoutePresets,
    ): int {
        $presetKey = $this->resolvePresetKey();

        if ($presetKey === null) {
            $this->error('No presets are configured.');

            return self::FAILURE;
        }

        $preset = config("presets.presets.{$presetKey}");

        if (! is_array($preset)) {
            $this->error("Preset [{$presetKey}] does not exist.");

            return self::FAILURE;
        }

        $this->info("Syncing preset package [{$presetKey}]...");

        try {
            if ($this->hasConfiguredGroups($preset, 'contact_statuses')) {
                $this->renderContactStatusResult(
                    $syncContactStatusPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Contact statuses: no groups configured; skipped.');
            }

            if ($this->hasConfiguredGroups($preset, 'tasks')) {
                $this->renderTaskResult(
                    $syncTaskPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Task templates: no groups configured; skipped.');
            }

            if ($this->hasConfiguredGroups($preset, 'campaigns')) {
                $this->renderCampaignResult(
                    $syncCampaignPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Campaigns: no groups configured; skipped.');
            }

            if ($this->hasConfiguredGroups($preset, 'flow_routes')) {
                $this->renderFlowRouteResult(
                    $syncFlowRoutePresets->handle(
                        presetKey: $presetKey,
                        force: (bool) $this->option('force-flow-routes'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('FlowRoutes: no groups configured; skipped.');
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Preset package [{$presetKey}] synced.");

        return self::SUCCESS;
    }

    private function resolvePresetKey(): ?string
    {
        $argumentPreset = $this->argument('preset');

        if (is_string($argumentPreset) && trim($argumentPreset) !== '') {
            return trim($argumentPreset);
        }

        $presetKeys = array_keys(config('presets.presets', []));

        $presetKeys = array_values(array_filter(
            $presetKeys,
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        if ($presetKeys === []) {
            return null;
        }

        $defaultPreset = config('presets.default');

        $default = is_string($defaultPreset) && in_array($defaultPreset, $presetKeys, true)
            ? $defaultPreset
            : $presetKeys[0];

        return $this->choice(
            question: 'Which preset package should be synced?',
            choices: $presetKeys,
            default: $default,
        );
    }

    /**
     * @param array<string, mixed> $preset
     */
    private function hasConfiguredGroups(array $preset, string $section): bool
    {
        $groups = $preset[$section]['groups'] ?? null;

        return is_array($groups) && $groups !== [];
    }

    /**
     * @param array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: array<int, string>
     * } $result
     */
    private function renderContactStatusResult(array $result): void
    {
        $this->line('');
        $this->info('Contact statuses');

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
    }

    private function renderTaskResult(object $result): void
    {
        $this->line('');
        $this->info('Task templates');

        $this->table(
            ['Item', 'Count'],
            [
                ['Created', $result->created],
                ['Updated', $result->updated],
                ['Skipped', $result->skipped],
            ],
        );

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($result->errors as $error) {
            $this->error($error);
        }
    }

    private function renderCampaignResult(object $result): void
    {
        $this->line('');
        $this->info('Campaigns');

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
    }

    private function renderFlowRouteResult(object $result): void
    {
        $this->line('');
        $this->info('FlowRoutes');

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
    }
}