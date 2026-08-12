<?php

namespace App\Modules\Forms\Providers;

use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContract;
use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContractTargetProvider;
use App\Modules\Forms\Validation\FormsSetupValidationContributor;
use Illuminate\Support\ServiceProvider;

class FormsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            FormsSetupValidationContributor::class,
            'setup.validation_contributors',
        );

        $this->app->tag(
            FormDefinitionConfigContract::class,
            'config.contracts',
        );

        $this->app->tag(
            FormDefinitionConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );
    }

    public function boot(): void
    {
        //
    }
}