<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationPathPolicy;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformMigrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPLETE_TEST_MODULE_KEYS = [
        'core',
        'messaging',
        'inbound_messaging',
        'internal_notifications',
        'tasks',
        'scheduling',
        'portal',
        'forms',
        'documents',
        'commerce',
        'location',
        'events',
        'workflow',
        'flow_routes',
        'campaigns',
        'broadcasts',
        'webinars',
    ];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_runtime_startup_path_policy_registers_only_platform_schema(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $policy = app(ModuleMigrationPathPolicy::class);

        $this->assertEquals([
            $registry->platform()->path,
        ], $policy->runtimeStartupPaths());
    }

    public function test_test_bootstrap_registers_complete_non_vertical_module_schema(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $policy = app(ModuleMigrationPathPolicy::class);
        $paths = app(Migrator::class)->paths();
        $expectedModulePaths = array_map(
            static fn (string $moduleKey): string => $registry
                ->requireModule($moduleKey)
                ->path,
            self::COMPLETE_TEST_MODULE_KEYS,
        );

        $this->assertEquals(
            $expectedModulePaths,
            $policy->completeTestModulePaths(),
        );
        $this->assertContains(
            base_path($registry->platform()->path),
            $paths,
        );

        foreach ($expectedModulePaths as $path) {
            $this->assertContains(
                base_path($path),
                $paths,
            );
        }

        $this->assertNotContains(
            base_path($registry->requireModule('mortgage')->path),
            $paths,
        );
        $this->assertTrue(Schema::hasTable('module_installations'));
    }

    public function test_no_module_owned_migrations_exist_in_the_legacy_root(): void
    {
        $legacyRootFiles = collect(File::files(database_path('migrations')))
            ->filter(
                static fn (\SplFileInfo $file): bool => $file->getExtension() === 'php',
            )
            ->map(
                static fn (\SplFileInfo $file): string => $file->getFilename(),
            )
            ->sort()
            ->values()
            ->all();

        $this->assertEquals([], $legacyRootFiles);
    }

    public function test_installation_repository_records_module_level_state_without_replacing_laravel_history(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 18:30:00 UTC');

        $registry = app(ModuleMigrationRegistry::class);
        $repository = app(ModuleInstallationRepository::class);
        $scope = $registry->requireModule('scheduling');

        $this->assertFalse($repository->installed('scheduling'));

        $installing = $repository->begin('scheduling');

        $this->assertSame('scheduling', $installing->module_key);
        $this->assertSame(ModuleInstallation::STATUS_INSTALLING, $installing->status);
        $this->assertSame($scope->schemaVersion, $installing->schema_version);
        $this->assertSame(
            $registry->manifestHash($scope),
            $installing->manifest_hash,
        );
        $this->assertNull($installing->installed_at);
        $this->assertNull($installing->last_migrated_at);

        $installed = $repository->markInstalled('scheduling');

        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $installed->status);
        $this->assertSame(
            '2026-08-05 18:30:00',
            $installed->installed_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-05 18:30:00',
            $installed->last_migrated_at?->format('Y-m-d H:i:s'),
        );
        $this->assertTrue($repository->installed('scheduling'));

        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'schema_version' => $scope->schemaVersion,
            'manifest_hash' => $registry->manifestHash($scope),
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_05_180000_create_module_installations_table',
        ]);
    }

    public function test_failed_installation_state_remains_distinct_from_installed_state(): void
    {
        $repository = app(ModuleInstallationRepository::class);

        $failed = $repository->markFailed('location');

        $this->assertSame(ModuleInstallation::STATUS_FAILED, $failed->status);
        $this->assertFalse($repository->installed('location'));
        $this->assertNull($failed->installed_at);
        $this->assertNull($failed->last_migrated_at);
    }
}