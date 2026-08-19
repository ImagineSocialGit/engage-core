<?php

return [
    'version' => 1,
    'optional' => true,
    'activation_tables' => [
        'contact_relationships',
    ],
    'tables' => [
        'contact_relationships' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'contact_id',
                'relationship_key',
                'stage_key',
                'source',
                'subsource',
                'is_active',
                'started_at',
                'ended_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_id' => 'contacts',
            ],
        ],
    ],
];