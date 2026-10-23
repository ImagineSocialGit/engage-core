<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contact Import Profiles
    |--------------------------------------------------------------------------
    |
    | Profiles are optional operator aids for known CSV exports. A profile may
    | suggest header mappings and provide trusted dataset-level defaults such
    | as relationship type or source system. It does not bypass the normal CSV
    | preview/mapping screen and it never changes Contact identity matching.
    |
    | Client config owns client/export-specific profiles. Core ships none.
    |
    */

    'storage' => [
        // Local developer/test fixtures keep working. All remotely hosted
        // environments default to a private Spaces-backed disk.
        'disk' => env(
            'CONTACT_IMPORT_SOURCE_DISK',
            in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
                ? 'local'
                : 'contact_imports',
        ),
    ],

    'processing' => [
        'chunk_rows' => 500,
    ],

    'profiles' => [],
];