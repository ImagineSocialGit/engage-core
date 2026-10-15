<?php

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Services\BillingCollectionAmountResolver;
use App\Modules\Billing\Services\BillingCollectionProviderRegistry;
use Illuminate\Support\ServiceProvider;

class BillingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillingCollectionAmountResolver::class);

        $this->app->singleton(
            BillingCollectionProviderRegistry::class,
            fn ($app): BillingCollectionProviderRegistry => new BillingCollectionProviderRegistry(
                providers: $app->tagged(BillingCollectionProviderRegistry::PROVIDER_TAG),
            ),
        );
    }

    public function boot(): void
    {
        //
    }
}