<?php

namespace App\Providers;

use App\Console\Commands\SyncPresetsCommand;
use App\Console\Commands\ValidateSetupCommand;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\Contracts\ModuleDefinitionConfigContract;
use App\Support\ConfigContracts\Contracts\PresetPackageConfigContract;
use App\Support\Modules\ModuleManager;
use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use App\Support\SetupValidation\Contributors\ModuleDependenciesSetupValidationContributor;
use App\Support\SetupValidation\Contributors\PresetCompositionSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ReferenceRegistrySetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);

        $this->app->singleton(ConfigContractRegistry::class, function ($app): ConfigContractRegistry {
            return new ConfigContractRegistry(
                contracts: $app->tagged('config.contracts'),
            );
        });

        $this->app->tag([
            ModuleDefinitionConfigContract::class,
            PresetPackageConfigContract::class,
        ], 'config.contracts');

        $this->app->singleton(TokenContractRegistry::class, function ($app): TokenContractRegistry {
            return new TokenContractRegistry(
                sourceProviders: $app->tagged('token.source_providers'),
                contextProviders: $app->tagged('token.context_providers'),
            );
        });

        $this->app->singleton(PresetContributionRegistry::class, function ($app): PresetContributionRegistry {
            $contributors = [];

            foreach ($app->make(ModuleManager::class)->presetContributorClasses() as $contributorClass) {
                if (! class_exists($contributorClass)) {
                    throw new InvalidArgumentException(
                        "Configured preset contributor class [{$contributorClass}] does not exist."
                    );
                }

                $contributor = $app->make($contributorClass);

                if (! $contributor instanceof PresetContributor) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured preset contributor [%s] must implement [%s].',
                        $contributorClass,
                        PresetContributor::class,
                    ));
                }

                $contributors[] = $contributor;
            }

            return new PresetContributionRegistry($contributors);
        });

        $this->app->singleton(PresetPackageResolver::class);
        $this->app->singleton(PresetCompositionResolver::class);

        $this->app->singleton(SetupValidationManager::class, function ($app): SetupValidationManager {
            return new SetupValidationManager(
                contributors: $app->tagged('setup.validation_contributors'),
            );
        });

        $this->app->tag([
            ModuleDependenciesSetupValidationContributor::class,
            PresetCompositionSetupValidationContributor::class,
            ReferenceRegistrySetupValidationContributor::class,
        ], 'setup.validation_contributors');
    }

    public function boot(): void
    {
        Event::listen(
            AutomationEventRecorded::class,
            RecordAutomationEventCorrelationEvidenceAction::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPresetsCommand::class,
                ValidateSetupCommand::class,
            ]);
        }
    }
}
