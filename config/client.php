<?php

$clientKey = env('CLIENT_KEY', 'default');

$clientPath = base_path('client/'.$clientKey);

return [
    'key' => $clientKey,
    'preset' => null,
    'path' => $clientPath,
    'config_path' => $clientPath.'/config',
    'views_path' => $clientPath.'/resources/views',
    'env_path' => $clientPath.'/.env',

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