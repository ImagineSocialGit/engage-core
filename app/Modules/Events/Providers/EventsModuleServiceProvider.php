<?php

namespace App\Modules\Events\Providers;

use App\Modules\Events\Services\EventDefinitionRegistry;
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
    }

    public function boot(): void
    {
        // Runtime routes, jobs, and UI are added only by later Events batches.
    }
}