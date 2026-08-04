<?php

namespace App\Modules\Location\Providers;

use App\Modules\Location\Contracts\LocationNormalizationProvider;
use App\Modules\Location\Services\DeterministicLocationNormalizationProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LocationModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            DeterministicLocationNormalizationProvider::class,
        );

        $this->app->singleton(
            LocationNormalizationProvider::class,
            function (Application $app): LocationNormalizationProvider {
                $providerClass = config(
                    'location.normalization.provider',
                    DeterministicLocationNormalizationProvider::class,
                );

                if (! is_string($providerClass) || trim($providerClass) === '') {
                    throw new InvalidArgumentException(
                        'Location normalization provider configuration must be a non-empty class name.',
                    );
                }

                $providerClass = trim($providerClass);

                if (! class_exists($providerClass)) {
                    throw new InvalidArgumentException(
                        "Configured Location normalization provider [{$providerClass}] does not exist.",
                    );
                }

                $provider = $app->make($providerClass);

                if (! $provider instanceof LocationNormalizationProvider) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured Location normalization provider [%s] must implement [%s].',
                        $providerClass,
                        LocationNormalizationProvider::class,
                    ));
                }

                return $provider;
            },
        );
    }

    public function boot(): void
    {
        //
    }
}