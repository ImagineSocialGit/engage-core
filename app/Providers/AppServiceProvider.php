<?php

namespace App\Providers;

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
        //
    }
}