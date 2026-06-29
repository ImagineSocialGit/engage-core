<?php

namespace App\Providers;

use App\Console\Commands\SyncPresetsCommand;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPresetsCommand::class,
            ]);
        }
    }
}