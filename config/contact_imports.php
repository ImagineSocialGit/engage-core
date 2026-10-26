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

    'recovery' => [
        // Only imports with no durable checkpoint movement for this long
        // can be redispatched. Keep above the chunk/finalization job timeout.
        'stale_after_seconds' => (int) env('CONTACT_IMPORT_RECOVERY_STALE_SECONDS', 300),
        // A failed Redis handoff can be attempted again once this claim ages.
        'redispatch_after_seconds' => (int) env('CONTACT_IMPORT_RECOVERY_RETRY_SECONDS', 300),
        'max_per_run' => (int) env('CONTACT_IMPORT_RECOVERY_MAX_PER_RUN', 25),
    ],

    'automation_events_recovery' => [
        // Wait past the emitter timeout before reconstructing a lost job.
        'stale_after_seconds' => (int) env('CONTACT_IMPORT_EVENTS_RECOVERY_STALE_SECONDS', 300),
        'redispatch_after_seconds' => (int) env('CONTACT_IMPORT_EVENTS_RECOVERY_RETRY_SECONDS', 300),
        'max_per_run' => (int) env('CONTACT_IMPORT_EVENTS_RECOVERY_MAX_PER_RUN', 25),
    ],

    'profiles' => [],
];