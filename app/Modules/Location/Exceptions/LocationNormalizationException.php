<?php

namespace App\Modules\Location\Exceptions;

use RuntimeException;
use Throwable;

class LocationNormalizationException extends RuntimeException
{
    public static function providerFailure(
        string $provider,
        Throwable $previous,
    ): self {
        return new self(
            message: "Location normalization provider [{$provider}] failed: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}