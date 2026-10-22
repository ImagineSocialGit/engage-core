<?php

namespace Tests\Feature\Modules;

use App\Providers\ModuleIntegrationServiceProvider;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\ModuleIntegrations\Messaging\Tasks\ScheduledMessageTaskLinkPresenter;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class ModuleIntegrationBootstrapTest extends TestCase
{
    public function test_cross_module_bridges_boot_as_platform_infrastructure(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertContains(ModuleIntegrationServiceProvider::class, $providers);
        $this->assertNotContains(IntegrationsModuleServiceProvider::class, $providers);
        $this->assertFalse(app(ModuleManager::class)->known('integrations'));
        $this->assertSame([], app(ModuleManager::class)->providers('integrations'));
    }

    public function test_retired_enabled_key_is_ignored_for_older_client_configuration(): void
    {
        config()->set('modules.enabled', ['messaging', 'tasks', 'integrations']);

        $modules = app(ModuleManager::class);

        $this->assertFalse($modules->enabled('integrations'));
        $this->assertTrue($modules->enabled('messaging'));
        $this->assertTrue($modules->enabled('tasks'));
        $this->assertNotContains('integrations', $modules->enabledKeysWithDependencies());
    }

    public function test_cross_module_tasks_messaging_bridge_does_not_require_an_integrations_module(): void
    {
        config()->set('modules.enabled', ['messaging', 'tasks']);

        (new ModuleIntegrationServiceProvider($this->app))->register();

        $classes = array_map(
            static fn (object $presenter): string => $presenter::class,
            iterator_to_array($this->app->tagged('tasks.link_presenters')),
        );

        $this->assertContains(ScheduledMessageTaskLinkPresenter::class, $classes);
    }

    public function test_existing_provider_class_remains_compatible(): void
    {
        $this->assertTrue(is_subclass_of(
            IntegrationsModuleServiceProvider::class,
            ModuleIntegrationServiceProvider::class,
        ));
    }
}