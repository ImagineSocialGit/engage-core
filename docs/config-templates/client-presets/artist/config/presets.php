<?php

$contacts = [
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
];

$emptyGroups = [
    'contact_statuses' => [],
    'tasks' => [],
    'campaigns' => [],
    'flow_routes' => [],
    'forms' => [],
];

return [
    'packages' => [
        'artist' => [
            'name' => 'Artist Audience',
            'description' => 'Artist fan-engagement package with Core-backed updates intake.',
            'contacts' => $contacts,
            'groups' => [
                ...$emptyGroups,
                'forms' => [
                    'artist_updates',
                ],
            ],
        ],

        'artist_management' => [
            'name' => 'Artist Management',
            'description' => 'Artist operational-management package without audience-marketing preset definitions.',
            'contacts' => $contacts,
            'groups' => $emptyGroups,
        ],

        'artist_full' => [
            'name' => 'Full Artist',
            'description' => 'Artist audience and management package with Core-backed updates intake.',
            'contacts' => $contacts,
            'groups' => [
                ...$emptyGroups,
                'forms' => [
                    'artist_updates',
                ],
            ],
        ],
    ],
];