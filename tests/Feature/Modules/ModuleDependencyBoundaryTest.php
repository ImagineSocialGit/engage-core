<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\ModuleManager;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleDependencyBoundaryTest extends TestCase
{
    public function test_module_manager_expands_enabled_module_dependencies(): void
    {
        config()->set('modules.enabled', [
            'flow_routes',
            'inbound_messaging',
        ]);

        $enabled = app(ModuleManager::class)->enabledKeysWithDependencies();

        $this->assertContains('core', $enabled);
        $this->assertContains('workflow', $enabled);
        $this->assertContains('flow_routes', $enabled);
        $this->assertContains('messaging', $enabled);
        $this->assertContains('inbound_messaging', $enabled);

        $this->assertLessThan(
            array_search('flow_routes', $enabled, true),
            array_search('workflow', $enabled, true),
        );

        $this->assertLessThan(
            array_search('inbound_messaging', $enabled, true),
            array_search('messaging', $enabled, true),
        );
    }

    public function test_installed_modules_are_registered_in_module_config(): void
    {
        $registered = array_keys(config('modules.modules', []));

        $this->assertContains('core', $registered);
        $this->assertContains('messaging', $registered);
        $this->assertContains('inbound_messaging', $registered);
        $this->assertContains('internal_notifications', $registered);
        $this->assertContains('tasks', $registered);
        $this->assertContains('workflow', $registered);
        $this->assertContains('flow_routes', $registered);
        $this->assertContains('campaigns', $registered);
        $this->assertContains('broadcasts', $registered);
        $this->assertContains('webinars', $registered);
        $this->assertContains('mortgage', $registered);
        $this->assertContains('reporting', $registered);
    }

    public function test_messaging_module_does_not_import_feature_modules(): void
    {
        $this->assertModuleDoesNotImport('Messaging', [
            'Broadcasts',
            'Campaigns',
            'FlowRoutes',
            'InboundMessaging',
            'InternalNotifications',
            'Mortgage',
            'Reporting',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);
    }

    public function test_inbound_messaging_module_does_not_import_internal_notifications(): void
    {
        $this->assertModuleDoesNotImport('InboundMessaging', [
            'InternalNotifications',
        ]);
    }

    public function test_core_module_does_not_import_feature_modules(): void
    {
        $this->assertModuleDoesNotImport('Core', [
            'Broadcasts',
            'Campaigns',
            'FlowRoutes',
            'InboundMessaging',
            'InternalNotifications',
            'Messaging',
            'Mortgage',
            'Reporting',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);
    }

    public function test_workflow_module_does_not_import_flow_routes(): void
    {
        $this->assertModuleDoesNotImport('Workflow', [
            'FlowRoutes',
        ]);
    }

    public function test_campaigns_and_broadcasts_remain_separate(): void
    {
        $this->assertModuleDoesNotImport('Campaigns', [
            'Broadcasts',
            'FlowRoutes',
            'Mortgage',
            'Webinars',
            'Workflow',
        ]);

        $this->assertModuleDoesNotImport('Broadcasts', [
            'Campaigns',
            'FlowRoutes',
            'Mortgage',
            'Webinars',
            'Workflow',
        ]);
    }

    public function test_webinars_does_not_own_route_or_campaign_orchestration(): void
    {
        $this->assertModuleDoesNotImport('Webinars', [
            'Broadcasts',
            'Campaigns',
            'FlowRoutes',
            'Mortgage',
            'Workflow',
        ]);
    }

    public function test_messaging_dispatch_internals_are_not_imported_by_other_modules(): void
    {
        $this->assertAppDoesNotImportOutsideModule(
            allowedModule: 'Messaging',
            forbiddenImports: [
                'App\\Modules\\Messaging\\Services\\MessageDispatchDefinitionNormalizer',
                'App\\Modules\\Messaging\\Services\\MessageDispatchDefinitionMatcher',
                'App\\Modules\\Messaging\\Services\\MessageSendTimeResolver',
                'App\\Modules\\Messaging\\Services\\MessageDedupeKeyBuilder',
            ],
        );
    }

    /**
     * @param  array<int, string>  $forbiddenModules
     */
    private function assertModuleDoesNotImport(string $module, array $forbiddenModules): void
    {
        $basePath = app_path("Modules/{$module}");

        $this->assertDirectoryExists($basePath);

        $violations = [];

        foreach ($this->phpFiles($basePath) as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($forbiddenModules as $forbiddenModule) {
                $needle = "App\\Modules\\{$forbiddenModule}\\";

                if (! Str::contains($contents, $needle)) {
                    continue;
                }

                $violations[] = str_replace(base_path().'/', '', $file).' imports '.$needle;
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @param  array<int, string>  $forbiddenImports
     */
    private function assertAppDoesNotImportOutsideModule(
        string $allowedModule,
        array $forbiddenImports,
    ): void {
        $basePath = app_path('Modules');

        $this->assertDirectoryExists($basePath);

        $allowedPathPrefix = app_path("Modules/{$allowedModule}").DIRECTORY_SEPARATOR;

        $violations = [];

        foreach ($this->phpFiles($basePath) as $file) {
            if (str_starts_with($file, $allowedPathPrefix)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($forbiddenImports as $forbiddenImport) {
                if (! Str::contains($contents, $forbiddenImport)) {
                    continue;
                }

                $violations[] = str_replace(base_path().'/', '', $file).' imports private service '.$forbiddenImport;
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $path): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}