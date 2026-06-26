<?php

namespace App\Providers;

use App\Support\Modules\ModuleManager;
use Illuminate\Support\ServiceProvider;

class ModuleBootstrapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $modules = $this->app->make(ModuleManager::class);

        foreach ($modules->enabledProviders() as $provider) {
            if (! class_exists($provider)) {
                continue;
            }

            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        //
    }
}