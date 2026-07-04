<?php

$clientKey = env('CLIENT_KEY', 'default');

$clientPath = base_path('client/'.$clientKey);

return [
    'key' => $clientKey,
    'preset' => env('CLIENT_PRESET', config('presets.default_package')),
    'path' => $clientPath,
    'config_path' => $clientPath.'/config',
    'views_path' => $clientPath.'/resources/views',
    'env_path' => $clientPath.'/.env',
    'env' => [],

    'timezone' => env('CLIENT_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
];