<?php

namespace App\Providers;

use App\Console\Commands\SyncPresetsCommand;
use App\Console\Commands\ValidateSetupCommand;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\Modules\ModuleManager;
use App\Support\SetupValidation\Contributors\ModuleDependenciesSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ReferenceRegistrySetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);

        $this->app->singleton(SetupValidationManager::class, function ($app): SetupValidationManager {
            return new SetupValidationManager(
                contributors: $app->tagged('setup.validation_contributors'),
            );
        });

        $this->app->tag([
            ModuleDependenciesSetupValidationContributor::class,
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
