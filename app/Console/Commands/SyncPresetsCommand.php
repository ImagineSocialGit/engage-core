<?php

namespace App\Console\Commands;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Console\Command;
use Throwable;

class SyncPresetsCommand extends Command
{
    protected $signature = 'presets:sync
        {preset? : Optional preset key, such as mortgage or webinar_funnel}
        {--force-flow-routes : Overwrite customized FlowRoutes, Points, and FlowRoutePoints}
        {--force-message-templates : Overwrite customized Messaging template presets and reactivate synced assignments}
        {--force-webinar-schedule-profiles : Reserved for future customized webinar schedule profile handling}';

    protected $description = 'Sync preset-owned database definitions in dependency-safe order.';

    public function handle(
        SyncContactStatusPresetsAction $syncContactStatusPresets,
        SyncTaskPresetsAction $syncTaskPresets,
        SyncWebinarScheduleProfilesAction $syncWebinarScheduleProfiles,
        SyncMessageTemplatePresetsAction $syncMessageTemplatePresets,
        SyncCampaignPresetsAction $syncCampaignPresets,
        SyncFlowRoutePresetsAction $syncFlowRoutePresets,
    ): int {
        $presetKey = $this->resolvePresetKey();

        if ($presetKey === null) {
            $this->error('No presets are configured.');

            return self::FAILURE;
        }

        $preset = config("presets.packages.{$presetKey}");

        if (! is_array($preset)) {
            $this->error("Preset [{$presetKey}] does not exist.");

            return self::FAILURE;
        }

        $enabledModules = $this->enabledModules($preset);

        $this->info("Syncing preset package [{$presetKey}]...");
        $this->line('Enabled modules: '.implode(', ', $enabledModules));

        try {
            if ($this->hasConfiguredGroups($preset, 'contact_statuses')) {
                $this->renderContactStatusResult(
                    $syncContactStatusPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Contact statuses: no groups configured; skipped.');
            }

            if ($this->shouldSyncSection($preset, 'tasks', 'tasks', $enabledModules)) {
                $this->renderTaskResult(
                    $syncTaskPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Task templates: module disabled or no groups configured; skipped.');
            }

            if (in_array('messaging', $enabledModules, true)) {
                $this->renderMessageTemplateResult(
                    $syncMessageTemplatePresets->handle(
                        force: (bool) $this->option('force-message-templates'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Messaging template presets: module disabled; skipped.');
            }

            if (in_array('webinars', $enabledModules, true)) {
                $this->renderWebinarScheduleProfileResult(
                    $syncWebinarScheduleProfiles->handle(
                        force: (bool) $this->option('force-webinar-schedule-profiles'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Webinar schedule profiles: module disabled; skipped.');
            }

            if ($this->shouldSyncSection($preset, 'campaigns', 'campaigns', $enabledModules)) {
                $this->renderCampaignResult(
                    $syncCampaignPresets->handle($presetKey),
                );
            } else {
                $this->line('');
                $this->warn('Campaigns: module disabled or no groups configured; skipped.');
            }

            if ($this->shouldSyncSection($preset, 'flow_routes', 'flow_routes', $enabledModules)) {
                $this->renderFlowRouteResult(
                    $syncFlowRoutePresets->handle(
                        presetKey: $presetKey,
                        force: (bool) $this->option('force-flow-routes'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('FlowRoutes: module disabled or no groups configured; skipped.');
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

        $clientPreset = config('client.preset');

        if (is_string($clientPreset) && trim($clientPreset) !== '') {
            return trim($clientPreset);
        }

        $defaultPreset = config('presets.default_package');

        if (is_string($defaultPreset) && trim($defaultPreset) !== '') {
            return trim($defaultPreset);
        }

        $presetKeys = array_keys(config('presets.packages', []));

        $presetKeys = array_values(array_filter(
            $presetKeys,
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        return $presetKeys[0] ?? null;
    }

    /**
     * @param array<string, mixed> $preset
     * @return array<int, string>
     */
    private function enabledModules(array $preset): array
    {
        $packageModules = $this->stringList(data_get($preset, 'modules.enabled', []));
        $addedModules = $this->stringList(config('client.modules.add', []));
        $removedModules = $this->stringList(config('client.modules.remove', []));

        $modules = array_values(array_unique([
            'core',
            ...$packageModules,
            ...$addedModules,
        ]));

        return array_values(array_diff($modules, $removedModules));
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<int, string> $enabledModules
     */
    private function shouldSyncSection(
        array $preset,
        string $section,
        string $module,
        array $enabledModules,
    ): bool {
        return in_array($module, $enabledModules, true)
            && $this->hasConfiguredGroups($preset, $section);
    }

    /**
     * @param array<string, mixed> $preset
     */
    private function hasConfiguredGroups(array $preset, string $section): bool
    {
        $groups = data_get($preset, "groups.{$section}");

        return is_array($groups) && $groups !== [];
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
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


    /**
     * @param array{
     *     created: int,
     *     updated: int,
     *     customized_skipped: int,
     *     assignments_created: int,
     *     assignments_updated: int,
     *     assignments_preserved: int,
     *     catalog_entries_created: int,
     *     catalog_entries_updated: int
     * } $result
     */
    private function renderMessageTemplateResult(array $result): void
    {
        $this->line('');
        $this->info('Messaging template presets');

        $this->table(
            ['Item', 'Count'],
            [
                ['Created', $result['created']],
                ['Updated', $result['updated']],
                ['Customized skipped', $result['customized_skipped']],
                ['Assignments created', $result['assignments_created']],
                ['Assignments updated', $result['assignments_updated']],
                ['Assignments preserved', $result['assignments_preserved']],
                ['Catalog entries created', $result['catalog_entries_created']],
                ['Catalog entries updated', $result['catalog_entries_updated']],
            ],
        );
    }


    /**
     * @param array{
     *     profiles_created: int,
     *     profiles_updated: int,
     *     items_created: int,
     *     items_updated: int,
     *     items_disabled: int
     * } $result
     */
    private function renderWebinarScheduleProfileResult(array $result): void
    {
        $this->line('');
        $this->info('Webinar schedule profiles');

        $this->table(
            ['Item', 'Count'],
            [
                ['Profiles created', $result['profiles_created']],
                ['Profiles updated', $result['profiles_updated']],
                ['Items created', $result['items_created']],
                ['Items updated', $result['items_updated']],
                ['Items disabled', $result['items_disabled']],
            ],
        );
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
                ['Variants created', $result->variantsCreated],
                ['Variants updated', $result->variantsUpdated],
                ['Variants skipped', $result->variantsSkipped],
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
