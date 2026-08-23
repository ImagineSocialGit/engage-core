<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleMigrationRegistryTest extends TestCase
{
    public function test_registry_declares_platform_and_schema_managed_module_ownership(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $platformDefinition = config('module_migrations.platform');
        $moduleDefinitions = config('module_migrations.modules');

        $this->assertIsArray($platformDefinition);
        $this->assertIsArray($moduleDefinitions);

        $platform = $registry->platform();

        $this->assertSame(
            (string) $platformDefinition['path'],
            $platform->path,
        );
        $this->assertSame(
            (int) $platformDefinition['schema_version'],
            $platform->schemaVersion,
        );
        $this->assertEquals(
            array_values($platformDefinition['migrations']),
            $platform->migrationFiles,
        );

        $this->assertEquals(
            array_keys($moduleDefinitions),
            array_keys($registry->modules()),
        );

        foreach ($moduleDefinitions as $moduleKey => $definition) {
            $this->assertIsArray($definition);

            $scope = $registry->requireModule((string) $moduleKey);

            $this->assertSame(
                (string) $definition['path'],
                $scope->path,
                "Migration scope [{$moduleKey}] path drifted from module_migrations config.",
            );
            $this->assertSame(
                (int) $definition['schema_version'],
                $scope->schemaVersion,
                "Migration scope [{$moduleKey}] schema version drifted from module_migrations config.",
            );
            $this->assertEquals(
                array_values($definition['migrations']),
                $scope->migrationFiles,
                "Migration scope [{$moduleKey}] manifest drifted from module_migrations config.",
            );
        }

        $this->assertFalse($registry->hasModule('dashboard'));
        $this->assertFalse($registry->hasModule('integrations'));
        $this->assertTrue($registry->hasModule('reporting'));
        $this->assertStringStartsWith(
            'database/migrations/modules/',
            $registry->requireModule('reporting')->path,
        );
        $this->assertStringStartsWith(
            'database/migrations/verticals/',
            $registry->requireModule('mortgage')->path,
        );
    }

    public function test_every_current_migration_has_exactly_one_registered_owner(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $currentFiles = collect(File::allFiles(database_path('migrations')))
            ->filter(
                static fn (\SplFileInfo $file): bool => $file->getExtension() === 'php',
            )
            ->map(
                static fn (\SplFileInfo $file): string => $file->getFilename(),
            )
            ->sort()
            ->values()
            ->all();

        $this->assertEquals($currentFiles, $registry->migrationFiles());

        foreach ($currentFiles as $migrationFile) {
            $this->assertNotNull(
                $registry->ownerFor($migrationFile),
                "Migration [{$migrationFile}] has no registered owner.",
            );
        }
    }

    public function test_scheduling_and_location_are_independent_schema_scopes(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $modules = app(ModuleManager::class);

        $this->assertEquals(['core'], $modules->dependencies('scheduling'));
        $this->assertEquals(['core'], $modules->dependencies('location'));
        $this->assertTrue($registry->hasModule('scheduling'));
        $this->assertTrue($registry->hasModule('location'));

        $scheduling = $registry->requireModule('scheduling');

        $this->assertFalse($scheduling->owns(
            '2026_08_10_040000_add_range_duration_policy_to_bookable_services.php',
        ));
        $this->assertTrue($scheduling->owns(
            '2026_04_15_195860_create_bookable_services_table.php',
        ));
        $this->assertFalse($scheduling->owns(
            '2026_08_04_190000_add_location_snapshots_to_booking_holds.php',
        ));
        $this->assertNotSame(
            $scheduling->path,
            $registry->requireModule('location')->path,
        );
    }

    public function test_registry_rejects_unknown_module_keys(): void
    {
        config()->set('module_migrations.modules.unknown_module', [
            'path' => 'database/migrations/modules/unknown_module',
            'schema_version' => 1,
            'migrations' => [
                '2026_08_05_000000_create_unknown_table.php',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Migration scope references unknown module [unknown_module].',
        );

        app(ModuleMigrationRegistry::class)->definitions();
    }

    public function test_registry_rejects_duplicate_target_paths(): void
    {
        config()->set(
            'module_migrations.modules.location.path',
            'database/migrations/modules/scheduling',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Migration scopes [scheduling] and [location] share target path [database/migrations/modules/scheduling].',
        );

        app(ModuleMigrationRegistry::class)->definitions();
    }

    public function test_registry_rejects_duplicate_migration_ownership(): void
    {
        $duplicate = config(
            'module_migrations.modules.scheduling.migrations.0',
        );

        config()->push(
            'module_migrations.modules.location.migrations',
            $duplicate,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Migration [{$duplicate}] is owned by both [scheduling] and [location].",
        );

        app(ModuleMigrationRegistry::class)->definitions();
    }

    public function test_scope_definitions_reject_unsafe_paths_and_unknown_fields(): void
    {
        config()->set(
            'module_migrations.modules.core.path',
            'database/migrations/../outside',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Migration scope [core] path must be a normalized repository-relative directory under database/migrations.',
        );

        app(ModuleMigrationRegistry::class)->definitions();
    }
}