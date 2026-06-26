<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Contracts\WebinarProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class WebinarProviderManager
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function provider(?string $name = null): WebinarProvider
    {
        $name = $name ?: config('webinars.provider');

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('No webinar provider is configured.');
        }

        $name = strtolower($name);

        $providerClass = config("webinars.providers.{$name}.provider");

        if (! is_string($providerClass) || $providerClass === '') {
            throw new InvalidArgumentException("Webinar provider [{$name}] is not configured.");
        }

        $provider = $this->container->make($providerClass);

        if (! $provider instanceof WebinarProvider) {
            throw new InvalidArgumentException(
                "Webinar provider [{$name}] must implement ".WebinarProvider::class.'.'
            );
        }

        return $provider;
    }
}