<?php

namespace App\Modules\Location\Contracts;

use App\Modules\Location\Data\LocationNormalizationInput;
use App\Modules\Location\Data\NormalizedLocationData;

interface LocationNormalizationProvider
{
    public function normalize(
        LocationNormalizationInput $input,
    ): NormalizedLocationData;
}