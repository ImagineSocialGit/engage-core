<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Deployment\MediaStorageDeploymentPlanContributor;
use Illuminate\Support\ServiceProvider;

final class MediaModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            MediaStorageDeploymentPlanContributor::class,
            'deployment.plan_contributors',
        );
    }
}