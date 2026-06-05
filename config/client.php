<?php

return [
    'key' => env('CLIENT_KEY', 'default'),

    'base_path' => base_path('../leadflow-clients/'.env('CLIENT_KEY', 'default')),

    'config_path' => base_path('../leadflow-clients/'.env('CLIENT_KEY', 'default').'/config'),

    'views_path' => base_path('../leadflow-clients/'.env('CLIENT_KEY', 'default').'/resources/views'),

    'public_path' => base_path('../leadflow-clients/'.env('CLIENT_KEY', 'default').'/public'),
];