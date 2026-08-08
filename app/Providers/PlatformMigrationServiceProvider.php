<?php

namespace App\Providers;

use App\Support\Modules\Migrations\ModuleMigrationPathPolicy;
use Illuminate\Support\ServiceProvider;

final class PlatformMigrationServiceProvider extends ServiceProvider
{
    public function boot(ModuleMigrationPathPolicy $paths): void
    {
        foreach ($paths->runtimeStartupPaths() as $path) {
            $this->loadMigrationsFrom(
                base_path($path),
            );
        }
    }
}