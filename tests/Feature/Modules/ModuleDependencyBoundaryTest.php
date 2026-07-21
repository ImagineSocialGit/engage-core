<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\ModuleManager;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        $this->assertContains('scheduling', $registered);
        $this->assertContains('portal', $registered);
        $this->assertContains('workflow', $registered);
        $this->assertContains('flow_routes', $registered);
        $this->assertContains('forms', $registered);
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
            'Forms',
            'InboundMessaging',
            'InternalNotifications',
            'Mortgage',
            'Portal',
            'Reporting',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);

        $this->assertOnlyAllowedImports('Messaging', 'App\\Modules\\FlowRoutes\\', [
            'app/Modules/Messaging/Models/ScheduledMessage.php',
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
            'Forms',
            'InboundMessaging',
            'InternalNotifications',
            'Messaging',
            'Mortgage',
            'Portal',
            'Reporting',
            'Scheduling',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);
    }




    public function test_forms_module_does_not_import_domain_or_delivery_modules(): void
    {
        $this->assertModuleDoesNotImport('Forms', [
            'Broadcasts',
            'Campaigns',
            'Documents',
            'FlowRoutes',
            'InboundMessaging',
            'InternalNotifications',
            'Messaging',
            'Mortgage',
            'PetServices',
            'Music',
            'Portal',
            'Scheduling',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);
    }

    public function test_portal_module_does_not_import_domain_or_delivery_modules(): void
    {
        $this->assertModuleDoesNotImport('Portal', [
            'Broadcasts',
            'Campaigns',
            'Documents',
            'FlowRoutes',
            'Forms',
            'InboundMessaging',
            'InternalNotifications',
            'Messaging',
            'Mortgage',
            'PetServices',
            'Music',
            'Scheduling',
            'Tasks',
            'Webinars',
            'Workflow',
        ]);
    }

    public function test_scheduling_module_does_not_import_location_models(): void
    {
        $this->assertModuleDoesNotImport('Scheduling', [
            'Location',
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

    public function test_flow_route_external_event_is_only_created_inside_flow_routes(): void
    {
        foreach ($this->modulePhpFilesExcept('FlowRoutes') as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString(
                'FlowRouteExternalEvent::make',
                $contents,
                "FlowRouteExternalEvent::make must stay inside FlowRoutes. Found in [{$file}].",
            );

            $this->assertStringNotContainsString(
                'App\\Modules\\FlowRoutes\\Data\\Events\\FlowRouteExternalEvent',
                $contents,
                "Producer modules must not import FlowRouteExternalEvent. Found in [{$file}].",
            );
        }
    }

    public function test_flow_routes_provider_only_listens_to_workflow_and_generic_automation_events(): void
    {
        $provider = base_path('app/Modules/FlowRoutes/Providers/FlowRoutesModuleServiceProvider.php');

        $contents = file_get_contents($provider);

        $this->assertStringContainsString('ContactWorkflowStatusChanged::class', $contents);
        $this->assertStringContainsString('AutomationEventRecorded::class', $contents);

        foreach ([
            'TaskCompleted::class',
            'WebinarRegistered::class',
            'WebinarCancelled::class',
            'WebinarAttended::class',
            'WebinarMissed::class',
            'WebinarEnded::class',
            'WebinarOutcomeRecorded::class',
            'BroadcastSent::class',
            'BroadcastSkipped::class',
            'BroadcastFailed::class',
            'CampaignEnrollmentStarted::class',
            'CampaignEnrollmentCompleted::class',
            'CampaignEnrollmentCancelled::class',
            'MortgageStageChanged::class',
        ] as $forbiddenListenerReference) {
            $this->assertStringNotContainsString(
                $forbiddenListenerReference,
                $contents,
                "FlowRoutes provider should not listen directly to producer-specific events [{$forbiddenListenerReference}].",
            );
        }
    }

    public function test_flow_routes_point_extension_infrastructure_does_not_import_optional_action_modules(): void
    {
        $files = [
            'app/Modules/FlowRoutes/ConfigContracts/FlowRoutePresetDefinitionConfigContract.php',
            'app/Modules/FlowRoutes/Validation/FlowRoutesSetupValidationContributor.php',
            'app/Modules/FlowRoutes/Providers/FlowRoutesModuleServiceProvider.php',
            'app/Modules/FlowRoutes/PointHandlers/AutomationActionPointHandler.php',
            'app/Modules/FlowRoutes/Services/PointHandlerRegistry.php',
        ];

        foreach ($files as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));

            $this->assertIsString($contents);

            foreach ([
                'App\\Modules\\Tasks\\',
                'App\\Modules\\Messaging\\',
                'App\\Modules\\Campaigns\\',
            ] as $forbiddenImport) {
                $this->assertStringNotContainsString(
                    $forbiddenImport,
                    $contents,
                    "FlowRoutes point extension infrastructure [{$relativePath}] must not import [{$forbiddenImport}].",
                );
            }
        }
    }

    public function test_tasks_module_does_not_import_optional_feature_modules(): void
    {
        $this->assertModuleDoesNotImport('Tasks', [
            'Broadcasts',
            'Campaigns',
            'Commerce',
            'Documents',
            'FlowRoutes',
            'Forms',
            'InboundMessaging',
            'InternalNotifications',
            'Location',
            'Messaging',
            'Mortgage',
            'Portal',
            'Reporting',
            'Scheduling',
            'Webinars',
            'Workflow',
        ]);
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
     * @param  array<int, string>  $allowedFiles
     */
    private function assertOnlyAllowedImports(string $module, string $needle, array $allowedFiles): void
    {
        $violations = [];

        foreach ($this->modulePhpFiles($module) as $file) {
            $contents = file_get_contents($file);

            if (! str_contains($contents, $needle)) {
                continue;
            }

            $relativePath = str_replace(base_path().'/', '', $file);

            if (! in_array($relativePath, $allowedFiles, true)) {
                $violations[] = $relativePath.' imports '.$needle;
            }
        }

        sort($violations);

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

    private function modulePhpFiles(string $module): array
    {
        $path = base_path("app/Modules/{$module}");

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
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

    /**
     * @return array<int, string>
     */
    private function modulePhpFilesExcept(string $excludedModule): array
    {
        $modulesPath = base_path('app/Modules');

        if (! is_dir($modulesPath)) {
            return [];
        }

        $files = [];

        foreach (scandir($modulesPath) ?: [] as $module) {
            if ($module === '.' || $module === '..' || $module === $excludedModule) {
                continue;
            }

            $modulePath = $modulesPath.'/'.$module;

            if (! is_dir($modulePath)) {
                continue;
            }

            $files = [
                ...$files,
                ...$this->modulePhpFiles($module),
            ];
        }

        sort($files);

        return $files;
    }

    public function test_flow_routes_authoring_infrastructure_does_not_import_optional_action_modules(): void
    {
        $files = [
            app_path('Modules/FlowRoutes/Services/FlowRouteEditorCatalog.php'),
            app_path('Modules/FlowRoutes/Services/FlowRoutePointAuthoringService.php'),
            app_path('Modules/FlowRoutes/Services/FlowRoutePresentationResolver.php'),
            app_path('Modules/FlowRoutes/Requests/StoreFlowRoutePointRequest.php'),
            app_path('Modules/FlowRoutes/Requests/UpdateFlowRoutePointRequest.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('App\\Modules\\Tasks\\', $contents, $file);
            $this->assertStringNotContainsString('App\\Modules\\Messaging\\', $contents, $file);
            $this->assertStringNotContainsString('App\\Modules\\Campaigns\\', $contents, $file);
        }

        $pointFields = file_get_contents(
            resource_path('views/crm/flow-routes/partials/point-fields.blade.php')
        );

        $this->assertIsString($pointFields);
        $this->assertStringNotContainsString('@switch($pointType)', $pointFields);
        $this->assertStringNotContainsString('FlowRoutePointType::CreateTask', $pointFields);
        $this->assertStringNotContainsString('FlowRoutePointType::SendMessage', $pointFields);
        $this->assertStringNotContainsString('FlowRoutePointType::EnrollCampaign', $pointFields);
        $this->assertStringNotContainsString('FlowRoutePointType::CancelCampaign', $pointFields);
    }

}