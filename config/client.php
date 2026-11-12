<?php

// The neutral test host must never inherit a selected real client's source.
// Tests that exercise client overrides explicitly supply their fixture path.
$testing = env('APP_ENV') === 'testing';
$clientKey = $testing ? 'default' : env('CLIENT_KEY', 'default');

$clientPath = base_path('client/'.$clientKey);

return [
    'key' => $clientKey,
    'preset' => null,
    'path' => $clientPath,
    'config_path' => $testing ? null : $clientPath.'/config',
    'views_path' => $testing ? null : $clientPath.'/resources/views',
    'env_path' => $testing ? null : $clientPath.'/.env',

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Dashboard preferences
    |--------------------------------------------------------------------------
    |
    | Client-owned dashboard slot overrides are applied after platform and
    | preset defaults. Leave this empty to inherit the selected defaults.
    | A client may reorder eligible panel keys, change slot maxima, or provide
    | per-panel priority overrides without creating database-owned layout state.
    |
    */
    'dashboard' => [
        'slots' => [],
    ],
];