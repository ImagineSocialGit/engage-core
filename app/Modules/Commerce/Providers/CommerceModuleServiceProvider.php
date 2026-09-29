<?php

namespace App\Modules\Commerce\Providers;

use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\ContactPanels\CommercePurchaseHistoryContactPanelProvider;
use App\Modules\Commerce\Validation\CommerceSetupValidationContributor;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
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
        $this->app->singleton(CommerceSetupValidationContributor::class);

        $this->app->tag(
            CommerceSetupValidationContributor::class,
            'setup.validation_contributors',
        );
    }

    public function boot(ContactPanelRegistry $contactPanels): void
    {
        $contactPanels->register(
            CommercePurchaseHistoryContactPanelProvider::class,
            'commerce',
        );
    }
}