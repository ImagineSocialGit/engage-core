<?php

namespace App\Modules\Location\Services;

use App\Modules\Location\Contracts\LocationNormalizationProvider;
use App\Modules\Location\Data\LocationNormalizationInput;
use App\Modules\Location\Data\NormalizedLocationData;

final class DeterministicLocationNormalizationProvider implements LocationNormalizationProvider
{
    public function normalize(
        LocationNormalizationInput $input,
    ): NormalizedLocationData {
        return NormalizedLocationData::fromInput(
            input: $input,
            formattedAddress: $this->formattedAddress($input),
        );
    }

    private function formattedAddress(
        LocationNormalizationInput $input,
    ): string {
        $regionAndPostalCode = trim(implode(' ', [
            $input->region,
            $input->postalCode,
        ]));

        return implode(', ', array_values(array_filter([
            $input->addressLine1,
            $input->addressLine2,
            $input->city,
            $regionAndPostalCode,
            $input->country,
        ], static fn (?string $value): bool => $value !== null && $value !== '')));
    }
}