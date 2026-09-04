<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Console\Commands\BackfillMediaImageFingerprintsCommand;
use App\Modules\Media\Deployment\MediaStorageDeploymentPlanContributor;
use App\Modules\Media\Validation\MediaSetupValidationContributor;
use Illuminate\Support\ServiceProvider;

final class MediaModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            MediaStorageDeploymentPlanContributor::class,
            'deployment.plan_contributors',
        );

        $this->app->tag(
            MediaSetupValidationContributor::class,
            'setup.validation_contributors',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillMediaImageFingerprintsCommand::class,
            ]);
        }
    }
}