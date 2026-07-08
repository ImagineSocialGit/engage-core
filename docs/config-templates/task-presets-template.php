<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Template Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets/tasks.php
    | client/{client-key}/config/presets/tasks.php, if client override exists
    |
    | Task presets create/update DB-owned task templates only.
    | They must not create live tasks.
    |
    | Runtime task creation should use CreateTaskAction.
    */

    'groups' => [
        'crm_default' => [
            'call_lead',
            'review_lead_notes',
        ],
    ],

    'definitions' => [
        'call_lead' => [
            'key' => 'call_lead',
            'title' => 'Call lead',
            'description' => 'Call the lead and record the outcome.',
            'priority' => 'normal',
            'responsible_party' => 'internal',
            'source' => 'preset',
            'is_active' => true,
            'source_version' => 1,
            'defaults' => [
                'due' => [
                    'type' => 'delay',
                    'minutes' => 1440,
                ],
            ],
            'meta' => [],
        ],

        'review_lead_notes' => [
            'key' => 'review_lead_notes',
            'title' => 'Review lead notes',
            'description' => 'Review lead history and determine the next best action.',
            'priority' => 'normal',
            'responsible_party' => 'internal',
            'source' => 'preset',
            'is_active' => true,
            'source_version' => 1,
            'defaults' => [],
            'meta' => [],
        ],
    ],

];
