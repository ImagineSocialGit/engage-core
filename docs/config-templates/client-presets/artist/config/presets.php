<?php

return [
    'packages' => [
        'artist' => [
            'name' => 'Artist',
            'description' => 'Artist fan-engagement package with Core-backed updates intake.',

            'contacts' => [
                'labels' => [
                    'singular' => 'contact',
                    'plural' => 'contacts',
                ],

                'routes' => [
                    'plural' => 'contacts',
                ],

                'sources' => [
                    'website' => [
                        'enabled' => true,
                    ],

                    'manual' => [
                        'enabled' => true,
                    ],
                ],
            ],

            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
                'forms' => [
                    'artist_updates',
                ],
            ],
        ],
    ],
];