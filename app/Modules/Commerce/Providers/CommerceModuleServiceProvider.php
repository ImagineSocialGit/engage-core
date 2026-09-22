<?php

namespace App\Modules\Commerce\Providers;

use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use Illuminate\Support\ServiceProvider;

class CommerceModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommerceProviderRegistry::class, function ($app): CommerceProviderRegistry {
            return new CommerceProviderRegistry(
                providers: $app->tagged(CommerceProviderRegistry::PROVIDER_TAG),
            );
        });

        $this->app->singleton(CommerceProviderRoleResolver::class);
    }

    public function boot(): void
    {
        //
    }
}