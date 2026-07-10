<?php

namespace Database\Seeders;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            UserSeeder::class,
        ]);

        $this->syncPresetDefinitions();
    }

    private function syncPresetDefinitions(): void
    {
        $packageResolver = app(PresetPackageResolver::class);
        $compositionResolver = app(PresetCompositionResolver::class);

        $presetKey = $packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        if ($this->moduleEnabled('campaigns')) {
            app(SyncCampaignPresetsAction::class)->handle(
                $compositionResolver->resolve($presetKey, PresetDomain::Campaigns),
            );
        }

        if ($this->moduleEnabled('flow_routes')) {
            app(SyncFlowRoutePresetsAction::class)->handle(
                $compositionResolver->resolve($presetKey, PresetDomain::FlowRoutes),
            );
        }
    }

    private function moduleEnabled(string $module): bool
    {
        if (! function_exists('module_enabled')) {
            return true;
        }

        return module_enabled($module);
    }
}