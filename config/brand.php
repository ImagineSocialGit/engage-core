<?php

return [

    'name' => config('client.name', config('app.name')),

    'favicons' => [
        'base_url' => null,

        'files' => [
            ['rel' => 'icon', 'type' => 'image/png', 'sizes' => '96x96', 'href' => '/favicon/favicon-96x96.png'],
            ['rel' => 'icon', 'type' => 'image/svg+xml', 'href' => '/favicon/favicon.svg'],
            ['rel' => 'shortcut icon', 'href' => '/favicon/favicon.ico'],
            ['rel' => 'apple-touch-icon', 'sizes' => '180x180', 'href' => '/favicon/apple-touch-icon.png'],
            ['rel' => 'manifest', 'href' => '/favicon/site.webmanifest'],
        ],
    ],
];