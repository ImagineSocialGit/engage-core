<?php

namespace App\Modules\Events\Providers;

use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventReadinessRegistry;
use Illuminate\Support\ServiceProvider;

class EventsModuleServiceProvider extends ServiceProvider
{
    public const DEFINITION_CONTRIBUTOR_TAG = 'events.definition_contributors';

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('events.php'), 'events');

        $this->app->singleton(
            EventDefinitionRegistry::class,
            function ($app): EventDefinitionRegistry {
                $definitions = config('events.definitions', []);

                return new EventDefinitionRegistry(
                    baseDefinitions: is_array($definitions) ? $definitions : [],
                    contributors: $app->tagged(self::DEFINITION_CONTRIBUTOR_TAG),
                );
            },
        );

        $this->app->tag(
            CoreEventReadinessContributor::class,
            EventReadinessRegistry::CONTRIBUTOR_TAG,
        );

        $this->app->singleton(
            EventReadinessRegistry::class,
            fn ($app): EventReadinessRegistry => new EventReadinessRegistry(
                contributors: $app->tagged(EventReadinessRegistry::CONTRIBUTOR_TAG),
            ),
        );
    }

    public function boot(): void
    {
        // Runtime routes, jobs, and UI are added only by later Events batches.
    }
}