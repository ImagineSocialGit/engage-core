<?php

namespace Tests\Feature\Core;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_settings_hub_contains_enabled_module_contributions_and_excludes_disabled_modules(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
        ]);

        $keys = collect(app(ModuleManager::class)->settingsItems())
            ->pluck('key')
            ->all();

        foreach ([
            'core.team',
            'core.business_days',
            'core.contact_maintenance',
            'tasks.task_templates',
            'flow_routes.route_assignments',
        ] as $key) {
            $this->assertContains($key, $keys);
        }

        $this->assertNotContains('messaging.message_templates', $keys);
    }

    public function test_settings_hub_ignores_contributions_without_a_registered_route(): void
    {
        $definition = config('modules.modules.core');
        $definition['settings'][1]['route'] = 'crm.settings.missing';
        config()->set('modules.modules.core', $definition);

        $items = collect(app(ModuleManager::class)->settingsItems());

        $this->assertNotContains('core.business_days', $items->pluck('key')->all());
    }


}