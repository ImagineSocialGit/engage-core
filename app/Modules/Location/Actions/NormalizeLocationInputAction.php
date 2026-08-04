<?php

namespace App\Modules\Location\Actions;

use App\Modules\Location\Contracts\LocationNormalizationProvider;
use App\Modules\Location\Data\LocationNormalizationInput;
use App\Modules\Location\Data\NormalizedLocationData;
use App\Modules\Location\Exceptions\LocationNormalizationException;
use Throwable;

final class NormalizeLocationInputAction
{
    public function __construct(
        private readonly LocationNormalizationProvider $provider,
    ) {}

    /**
     * @param array<string, mixed>|LocationNormalizationInput $input
     */
    public function handle(
        array|LocationNormalizationInput $input,
    ): NormalizedLocationData {
        $input = is_array($input)
            ? LocationNormalizationInput::fromArray($input)
            : $input;

        try {
            return $this->provider->normalize($input);
        } catch (LocationNormalizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw LocationNormalizationException::providerFailure(
                provider: $this->provider::class,
                previous: $exception,
            );
        }
    }
}