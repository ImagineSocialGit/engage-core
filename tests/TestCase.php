<?php

namespace Tests;

use App\Support\Modules\Migrations\ModuleMigrationPathPolicy;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?string $activeMigrationProfile = null;

    /**
     * Create the application with the complete non-vertical module schema,
     * plus any test-selected optional/vertical schema, registered before
     * RefreshDatabase and other test lifecycle traits run.
     *
     * RefreshDatabase's static migrated flag is valid only while the selected
     * migration-path profile stays the same. Changing profiles invalidates that
     * cache once; tests sharing the same profile continue reusing the schema.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $migrator = $app->make(Migrator::class);
        $paths = $app->make(ModuleMigrationPathPolicy::class);
        $registry = $app->make(ModuleMigrationRegistry::class);

        $migrationPaths = $paths->completeTestModulePaths();

        foreach ($this->additionalTestMigrationModuleKeys() as $moduleKey) {
            $migrationPaths[] = $registry->requireModule($moduleKey)->path;
        }

        $migrationPaths = array_values(array_unique($migrationPaths));

        foreach ($migrationPaths as $path) {
            $migrator->path(
                $app->basePath($path),
            );
        }

        $profile = hash('sha256', implode("\n", $migrationPaths));

        if (self::$activeMigrationProfile !== $profile) {
            RefreshDatabaseState::$migrated = false;
            self::$activeMigrationProfile = $profile;
        }

        return $app;
    }

    /**
     * Optional schema-owning module keys needed by this test class in addition
     * to the complete non-vertical test schema.
     *
     * @return array<int, string>
     */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return [];
    }
}