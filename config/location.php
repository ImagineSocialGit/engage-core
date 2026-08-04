<?php

use App\Modules\Location\Services\DeterministicLocationNormalizationProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Location normalization
    |--------------------------------------------------------------------------
    |
    | Location is a silent supporting module. The configured provider receives
    | one closed, server-owned address input and returns a provider-neutral
    | NormalizedLocationData value. It must not persist Location rows, expose raw
    | provider payloads, or make Scheduling/vertical policy decisions.
    |
    | The deterministic provider performs text cleanup and stable formatting only.
    | It deliberately does not invent coordinates, timezone, precision, confidence,
    | verification, or external provider identity.
    |
    | A future provider adapter may replace this class when a proven workflow needs
    | provider-backed enrichment. Credentials remain environment/provider config and
    | must not be placed in this file or returned through the public DTO.
    |
    */
    'normalization' => [
        'provider' => DeterministicLocationNormalizationProvider::class,
    ],
];