<?php

return [
    'groups' => [
        'default' => [
            'new',
            'active',
            'inactive',
        ],
    ],

    'definitions' => [
        'new' => [
            'key' => 'new',
            'name' => 'New',
            'description' => 'New contact who has not yet entered an active follow-up or working process.',
            'category' => 'general',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => 1,
        ],

        'active' => [
            'key' => 'active',
            'name' => 'Active',
            'description' => 'Contact currently involved in an active follow-up, service, sales, or engagement process.',
            'category' => 'general',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => 1,
        ],

        'inactive' => [
            'key' => 'inactive',
            'name' => 'Inactive',
            'description' => 'Contact is not currently active or progressing through a current workflow.',
            'category' => 'general',
            'sort_order' => 90,
            'is_active' => true,
            'source_version' => 1,
        ],
    ],
];