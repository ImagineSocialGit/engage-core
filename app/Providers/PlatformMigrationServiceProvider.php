<?php

namespace App\Providers;

use App\Support\Modules\Migrations\MigrationFailureRecovery;
use App\Support\Modules\Migrations\ModuleMigrationPathPolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\ServiceProvider;

final class PlatformMigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MigrationFailureRecovery::class);
    }

    public function boot(
        ModuleMigrationPathPolicy $paths,
        Dispatcher $events,
        MigrationFailureRecovery $failureRecovery,
    ): void {
        $events->listen(
            MigrationStarted::class,
            [$failureRecovery, 'migrationStarted'],
        );
        $events->listen(
            MigrationEnded::class,
            [$failureRecovery, 'migrationEnded'],
        );

        foreach ($paths->runtimeStartupPaths() as $path) {
            $this->loadMigrationsFrom(
                base_path($path),
            );
        }
    }
}