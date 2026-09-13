<?php

return [
    'default' => 'audience',

    'packages' => [
        'audience' => [
            'name' => 'Artist Audience',
            'description' => 'Fan acquisition, campaigns, forms, inbound replies, reusable media, integrations, and reporting.',
            'preset' => 'artist',
        ],

        'management' => [
            'name' => 'Artist Management',
            'description' => 'Operational contact management, tasks, workflow, relationships, and automation.',
            'preset' => 'artist_management',
            'modules' => [
                'tasks',
                'workflow',
                'flow_routes',
                'relationships',
            ],
        ],

        'full' => [
            'name' => 'Full Artist',
            'description' => 'Artist Audience plus Artist Management.',
            'preset' => 'artist_full',
            'includes' => [
                'audience',
                'management',
            ],
        ],
    ],
];