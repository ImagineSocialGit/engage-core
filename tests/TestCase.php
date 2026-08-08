<?php

namespace Tests;

use App\Support\Modules\Migrations\ModuleMigrationPathPolicy;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application with the complete non-vertical module schema
     * registered before RefreshDatabase and other test lifecycle traits run.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $migrator = $app->make(Migrator::class);
        $paths = $app->make(ModuleMigrationPathPolicy::class);

        foreach ($paths->completeTestModulePaths() as $path) {
            $migrator->path(
                $app->basePath($path),
            );
        }

        return $app;
    }
}