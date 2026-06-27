<?php

namespace Database\Seeders;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
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
        if ($this->moduleEnabled('campaigns')) {
            app(SyncCampaignPresetsAction::class)->handle();
        }

        if ($this->moduleEnabled('flow_routes')) {
            app(SyncFlowRoutePresetsAction::class)->handle();
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