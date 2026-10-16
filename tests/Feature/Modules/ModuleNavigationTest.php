<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    public function test_disabled_modules_do_not_contribute_navigation_items(): void
    {
        config()->set('modules.enabled', ['messaging']);

        $modules = app(ModuleManager::class);
        $enabled = $modules->enabledKeysWithDependencies();

        foreach ($modules->navigationItems() as $item) {
            $this->assertContains($item['module'], $enabled);
        }
    }

    public function test_navigation_ignores_configured_routes_that_are_not_registered(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $campaignDefinition = config('modules.modules.campaigns');
        $campaignDefinition['nav']['route'] = 'crm.campaigns.missing';
        config()->set('modules.modules.campaigns', $campaignDefinition);

        $campaignNavigation = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'campaigns');

        $this->assertNull($campaignNavigation);
    }

}