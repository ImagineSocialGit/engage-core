<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    public function test_enabled_modules_expose_their_registered_navigation_routes(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'campaigns',
            'webinars',
            'workflow',
            'flow_routes',
            'broadcasts',
        ]);

        $routes = collect(app(ModuleManager::class)->navigationItems())
            ->pluck('route')
            ->all();

        $this->assertContains('crm.campaigns.index', $routes);
        $this->assertContains('crm.messaging.message-templates.index', $routes);
        $this->assertContains('crm.webinar-series.index', $routes);
        $this->assertContains('crm.flow-routes.index', $routes);
        $this->assertContains('crm.broadcasts.index', $routes);
        $this->assertContains('crm.settings.index', $routes);
    }

    public function test_disabled_modules_do_not_expose_their_navigation_routes(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $routes = collect(app(ModuleManager::class)->navigationItems())
            ->pluck('route')
            ->all();

        $this->assertContains('crm.messaging.message-templates.index', $routes);
        $this->assertNotContains('crm.campaigns.index', $routes);
        $this->assertNotContains('crm.webinar-series.index', $routes);
        $this->assertNotContains('crm.flow-routes.index', $routes);
        $this->assertNotContains('crm.broadcasts.index', $routes);
    }

    public function test_campaign_navigation_does_not_require_messaging_to_be_explicitly_visible(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
        ]);

        $routes = collect(app(ModuleManager::class)->navigationItems())
            ->pluck('route')
            ->all();

        $this->assertContains('crm.campaigns.index', $routes);
        $this->assertNotContains('crm.messaging.message-templates.index', $routes);
    }

    public function test_campaign_navigation_uses_the_primary_campaign_workspace_route(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $campaignNavigation = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'campaigns');

        $this->assertIsArray($campaignNavigation);
        $this->assertSame('crm.campaigns.index', $campaignNavigation['route']);
        $this->assertSame(route('crm.campaigns.index'), $campaignNavigation['href']);
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