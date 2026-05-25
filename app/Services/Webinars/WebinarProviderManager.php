<?php

namespace App\Services\Webinars;

use App\Contracts\Webinars\WebinarProvider;
use App\Services\Webinars\Providers\ZoomWebinarProvider;
use InvalidArgumentException;

class WebinarProviderManager
{
    public function __construct(
        private readonly ZoomWebinarProvider $zoomWebinarProvider,
    ) {}

    public function provider(?string $name = null): WebinarProvider
    {
        $name = $name ?: config('webinars.provider');

        return match ($name) {
            'zoom' => $this->zoomWebinarProvider,

            default => throw new InvalidArgumentException(
                "Unsupported webinar provider [{$name}]."
            ),
        };
    }
}
