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
];
