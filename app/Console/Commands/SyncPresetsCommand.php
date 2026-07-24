<?php

namespace App\Console\Commands;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use App\Support\Modules\ModuleManager;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Console\Command;
use Throwable;

class SyncPresetsCommand extends Command
{
    protected $signature = 'presets:sync
        {preset? : Optional preset package key}
        {--force-contact-statuses : Overwrite customized contact statuses}
        {--force-flow-routes : Overwrite customized FlowRoutes and FlowRoutePoints}
        {--force-tasks : Overwrite customized task templates}
        {--force-message-templates : Overwrite customized Messaging template presets and reactivate synced assignments}
        {--force-webinar-schedule-profiles : Overwrite customized webinar schedule profiles and profile items}';

    protected $description = 'Sync preset-owned database definitions in dependency-safe order. Campaign sync intentionally preserves customized records and has no force mode.';

    public function handle(
        SyncContactStatusPresetsAction $syncContactStatusPresets,
        SyncTaskPresetsAction $syncTaskPresets,
        SyncWebinarScheduleProfilesAction $syncWebinarScheduleProfiles,
        SyncMessageTemplatePresetsAction $syncMessageTemplatePresets,
        SyncCampaignPresetsAction $syncCampaignPresets,
        SyncFlowRouteCapabilitiesAction $syncFlowRouteCapabilities,
        SyncFlowRoutePresetsAction $syncFlowRoutePresets,
        PresetCompositionResolver $compositionResolver,
        PresetPackageResolver $packageResolver,
        ModuleManager $moduleManager,
    ): int {
        $argumentPreset = $this->argument('preset');
        $presetKey = $packageResolver->resolvePresetKey(
            is_string($argumentPreset) ? $argumentPreset : null,
        );

        if ($presetKey === null) {
            $this->error('No presets are configured.');

            return self::FAILURE;
        }

        try {
            $packageResolver->package($presetKey);
            $runtimeModules = $moduleManager->enabledKeysWithDependencies();
        } catch (Throwable $exception) {
            $this->renderPresetResolutionFailure(
                presetKey: $presetKey,
                exception: $exception,
            );

            return self::FAILURE;
        }

        $this->info("Syncing preset package [{$presetKey}]...");
        $this->line('Runtime modules: '.implode(', ', $runtimeModules));

        try {
            if ($this->hasConfiguredGroups($packageResolver, $presetKey, PresetDomain::ContactStatuses)) {
                $this->renderContactStatusResult(
                    $syncContactStatusPresets->handle(
                        resolved: $compositionResolver->resolve($presetKey, PresetDomain::ContactStatuses),
                        force: (bool) $this->option('force-contact-statuses'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Contact statuses: no groups configured; skipped.');
            }

            if ($this->shouldSyncDomain(
                packageResolver: $packageResolver,
                presetKey: $presetKey,
                domain: PresetDomain::Tasks,
                module: 'tasks',
                runtimeModules: $runtimeModules,
            )) {
                $this->renderTaskResult(
                    $syncTaskPresets->handle(
                        resolved: $compositionResolver->resolve($presetKey, PresetDomain::Tasks),
                        force: (bool) $this->option('force-tasks'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Task templates: module disabled or no groups configured; skipped.');
            }

            if (in_array('messaging', $runtimeModules, true)) {
                $this->renderMessageTemplateResult(
                    $syncMessageTemplatePresets->handle(
                        force: (bool) $this->option('force-message-templates'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Messaging template presets: module disabled; skipped.');
            }

            if (in_array('webinars', $runtimeModules, true)) {
                $this->renderWebinarScheduleProfileResult(
                    $syncWebinarScheduleProfiles->handle(
                        force: (bool) $this->option('force-webinar-schedule-profiles'),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Webinar schedule profiles: module disabled; skipped.');
            }

            if ($this->shouldSyncDomain(
                packageResolver: $packageResolver,
                presetKey: $presetKey,
                domain: PresetDomain::Campaigns,
                module: 'campaigns',
                runtimeModules: $runtimeModules,
            )) {
                $this->renderCampaignResult(
                    $syncCampaignPresets->handle(
                        $compositionResolver->resolve($presetKey, PresetDomain::Campaigns),
                    ),
                );
            } else {
                $this->line('');
                $this->warn('Campaigns: module disabled or no groups configured; skipped.');
            }

            if (in_array('flow_routes', $runtimeModules, true)) {
                $this->renderFlowRouteCapabilityResult(
                    $syncFlowRouteCapabilities->handle(),
                );
            } else {
                $this->line('');
                $this->warn('FlowRoute capabilities: module disabled; skipped.');
            }

            if ($this->shouldSyncDomain(
                packageResolver: $packageResolver,
                presetKey: $presetKey,
                domain: PresetDomain::FlowRoutes,
                module: 'flow_routes',
                runtimeModules: $runtimeModules,
            )) {
                $this->renderFlowRouteResult(
                    $syncFlowRoutePresets->handle(
                        resolved: $compositionResolver->resolve($presetKey, PresetDomain::FlowRoutes),
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

    /**
     * @param array<int, string> $runtimeModules
     */
    private function shouldSyncDomain(
        PresetPackageResolver $packageResolver,
        string $presetKey,
        PresetDomain $domain,
        string $module,
        array $runtimeModules,
    ): bool {
        return in_array($module, $runtimeModules, true)
            && $this->hasConfiguredGroups($packageResolver, $presetKey, $domain);
    }

    private function hasConfiguredGroups(
        PresetPackageResolver $packageResolver,
        string $presetKey,
        PresetDomain $domain,
    ): bool {
        return $packageResolver->selectedGroups($presetKey, $domain) !== [];
    }

    private function renderPresetResolutionFailure(
        string $presetKey,
        Throwable $exception,
    ): void {
        $this->error($exception->getMessage());

        $availablePackages = array_values(array_filter(
            array_keys(config('presets.packages', [])),
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        $this->line('');
        $this->info('Available preset packages');

        if ($availablePackages === []) {
            $this->line('  None configured.');
        } else {
            foreach ($availablePackages as $package) {
                $this->line("  - {$package}");
            }
        }

        $clientKey = config('client.key');
        $clientConfigPath = config('client.config_path');

        $presetConfigPath = is_string($clientConfigPath) && trim($clientConfigPath) !== ''
            ? rtrim($clientConfigPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'presets.php'
            : null;

        $displayPath = $this->displayPath($presetConfigPath);

        $this->line('');
        $this->info('Client preset config');
        $this->line('Client: '.(
            is_string($clientKey) && trim($clientKey) !== ''
                ? trim($clientKey)
                : 'unknown'
        ));
        $this->line('Expected path: '.($displayPath ?? 'unknown'));
        $this->line('Exists: '.(
            is_string($presetConfigPath) && is_file($presetConfigPath)
                ? 'yes'
                : 'no'
        ));
    }

    private function displayPath(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $basePath)
            ? substr($path, strlen($basePath))
            : $path;
    }

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
                ['Removed stale', $result->removed ?? 0],
                ['Customized skipped', $result->customizedSkipped ?? 0],
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
                ['Removed stale', $result['stale_removed']],
                ['Assignments created', $result['assignments_created']],
                ['Assignments updated', $result['assignments_updated']],
                ['Assignments preserved', $result['assignments_preserved']],
                ['Catalog entries created', $result['catalog_entries_created']],
                ['Catalog entries updated', $result['catalog_entries_updated']],
            ],
        );
    }

    private function renderWebinarScheduleProfileResult(array $result): void
    {
        $this->line('');
        $this->info('Webinar schedule profiles');

        $this->table(
            ['Item', 'Count'],
            [
                ['Profiles created', $result['profiles_created']],
                ['Profiles updated', $result['profiles_updated']],
                ['Profiles customized skipped', $result['profiles_skipped']],
                ['Items created', $result['items_created']],
                ['Items updated', $result['items_updated']],
                ['Items customized skipped', $result['items_skipped']],
                ['Items disabled', $result['items_disabled']],
            ],
        );
    }

    private function renderCampaignResult(object $result): void
    {
        $this->line('');
        $this->info('Campaigns');
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
    }

    private function renderFlowRouteCapabilityResult(array $result): void
    {
        $this->line('');
        $this->info('FlowRoute capabilities');

        $this->table(
            ['Item', 'Count'],
            [
                ['Created', $result['created']],
                ['Updated', $result['updated']],
                ['Customized skipped', $result['customized_skipped']],
                ['Unavailable handlers', $result['unavailable_handlers']],
            ],
        );

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
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
                    'FlowRoutePoints',
                    $result->created['flow_route_points'] ?? 0,
                    $result->updated['flow_route_points'] ?? 0,
                    $result->skipped['flow_route_points'] ?? 0,
                ],
                [
                    'FlowRouteTriggerBindings',
                    $result->created['flow_route_trigger_bindings'] ?? 0,
                    $result->updated['flow_route_trigger_bindings'] ?? 0,
                    $result->skipped['flow_route_trigger_bindings'] ?? 0,
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