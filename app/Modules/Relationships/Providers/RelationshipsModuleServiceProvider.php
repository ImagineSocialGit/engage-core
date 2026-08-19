<?php

namespace App\Modules\Relationships\Providers;

use App\Modules\Relationships\Validation\RelationshipsSetupValidationContributor;
use Illuminate\Support\ServiceProvider;

class RelationshipsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            RelationshipsSetupValidationContributor::class,
            'setup.validation_contributors',
        );
    }
}