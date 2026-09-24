<?php

/*
|--------------------------------------------------------------------------
| Selected-Client Composer Packages
|--------------------------------------------------------------------------
|
| Copy this file to:
|
|   client/{CLIENT_KEY}/config/client_packages.php
|
| only when the selected client has private Composer packages to load.
| Environment entries declare ownership/sensitivity only. Store actual values
| in client/{CLIENT_KEY}/.env.
|
| Provider classes must come from client/{CLIENT_KEY}/vendor/autoload.php.
|
*/

return [
    'providers' => [
        // Vendor\Package\PackageServiceProvider::class,
    ],

    'environment' => [
        /*
        'EXAMPLE_API_KEY' => [
            'owner' => 'example',
            'secret' => true,
        ],

        'EXAMPLE_ACCOUNT_ID' => [
            'owner' => 'example',
            'secret' => false,
        ],
        */
    ],
];