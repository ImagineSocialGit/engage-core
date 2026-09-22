<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleMigrationRegistryTest extends TestCase
{
    public function test_registry_discovers_platform_and_schema_managed_module_contracts_from_paths(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $platformDefinition = config('module_migrations.platform');
        $moduleDefinitions = config('module_migrations.modules');

        $this->assertIsArray($platformDefinition);
        $this->assertIsArray($moduleDefinitions);

        $platform = $registry->platform();

        $this->assertSame((string) $platformDefinition['path'], $platform->path);
        $this->assertSame(count($platform->migrationFiles), $platform->schemaVersion);
        $this->assertSame($platform->migrationFiles, array_keys($platform->migrationChecksums));

        $this->assertEquals(
            array_keys($moduleDefinitions),
            array_keys($registry->modules()),
        );

        foreach ($moduleDefinitions as $moduleKey => $definition) {
            $this->assertIsArray($definition);
            $this->assertSame(['path'], array_keys($definition));

            $scope = $registry->requireModule((string) $moduleKey);

            $this->assertSame((string) $definition['path'], $scope->path);
            $this->assertSame(count($scope->migrationFiles), $scope->schemaVersion);
            $this->assertSame($scope->migrationFiles, array_keys($scope->migrationChecksums));

            foreach ($scope->migrationChecksums as $checksum) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $checksum);
            }
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
            $this->assertNotNull($registry->ownerFor($migrationFile));
        }
    }

    public function test_new_migration_is_discovered_without_configuration_changes_and_changes_manifest_when_contents_change(): void
    {
        $filename = '2099_01_01_000001_test_registry_discovery.php';
        $path = database_path('migrations/modules/core/'.$filename);

        File::put($path, "<?php\n\n// original\nreturn new class {};\n");

        try {
            $registry = app(ModuleMigrationRegistry::class);
            $scope = $registry->requireModule('core');
            $originalHash = $registry->manifestHash($scope);

            $this->assertContains($filename, $scope->migrationFiles);
            $this->assertSame(count($scope->migrationFiles), $scope->schemaVersion);

            File::put($path, "<?php\n\n// changed\nreturn new class {};\n");

            $changed = $registry->requireModule('core');

            $this->assertNotSame(
                $originalHash,
                $registry->manifestHash($changed),
            );
            $this->assertNotSame(
                $scope->checksum($filename),
                $changed->checksum($filename),
            );
        } finally {
            File::delete($path);
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
        $this->assertNotSame(
            $registry->requireModule('scheduling')->path,
            $registry->requireModule('location')->path,
        );
    }

    public function test_registry_rejects_unknown_module_keys(): void
    {
        config()->set('module_migrations.modules.unknown_module', [
            'path' => 'database/migrations/modules/core',
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

    public function test_registry_rejects_duplicate_discovered_migration_ownership(): void
    {
        $filename = '2099_01_01_000002_test_duplicate_migration_owner.php';
        $schedulingPath = database_path('migrations/modules/scheduling/'.$filename);
        $locationPath = database_path('migrations/modules/location/'.$filename);
        $source = "<?php\n\nreturn new class {};\n";

        File::put($schedulingPath, $source);
        File::put($locationPath, $source);

        try {
            app(ModuleMigrationRegistry::class)->definitions();

            $this->fail('Duplicate migration ownership must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                "Migration [{$filename}] is owned by both [scheduling] and [location].",
                $exception->getMessage(),
            );
        } finally {
            File::delete([$schedulingPath, $locationPath]);
        }
    }

    public function test_scope_definitions_reject_unsafe_unknown_missing_empty_and_escaping_paths(): void
    {
        config()->set(
            'module_migrations.modules.core.path',
            'database/migrations/../outside',
        );

        try {
            app(ModuleMigrationRegistry::class)->definitions();
            $this->fail('Unsafe migration paths must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'must be a normalized repository-relative directory',
                $exception->getMessage(),
            );
        }

        config()->set('module_migrations.modules.core', [
            'path' => 'database/migrations/modules/core',
            'migrations' => [],
        ]);

        try {
            app(ModuleMigrationRegistry::class)->definitions();
            $this->fail('Unknown migration-scope fields must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'contains unsupported field(s): [migrations]',
                $exception->getMessage(),
            );
        }

        config()->set('module_migrations.modules.core', [
            'path' => 'database/migrations/modules/missing-core',
        ]);

        try {
            app(ModuleMigrationRegistry::class)->definitions();
            $this->fail('Missing migration directories must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        $emptyPath = database_path('migrations/modules/test-empty-scope');
        File::ensureDirectoryExists($emptyPath);
        config()->set('module_migrations.modules.core', [
            'path' => 'database/migrations/modules/test-empty-scope',
        ]);

        try {
            app(ModuleMigrationRegistry::class)->definitions();
            $this->fail('Empty migration directories must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('contains no migration files', $exception->getMessage());
        } finally {
            File::deleteDirectory($emptyPath);
        }

        $outsidePath = storage_path('framework/testing/migration-scope-outside');
        $linkPath = database_path('migrations/modules/test-escaping-scope');
        File::ensureDirectoryExists($outsidePath);
        File::put($outsidePath.'/2099_01_01_000003_test_escape.php', "<?php\nreturn new class {};\n");
        @unlink($linkPath);
        symlink($outsidePath, $linkPath);
        config()->set('module_migrations.modules.core', [
            'path' => 'database/migrations/modules/test-escaping-scope',
        ]);

        try {
            app(ModuleMigrationRegistry::class)->definitions();
            $this->fail('Migration directories resolving outside database/migrations must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'must resolve beneath database/migrations',
                $exception->getMessage(),
            );
        } finally {
            @unlink($linkPath);
            File::deleteDirectory($outsidePath);
        }
    }
}