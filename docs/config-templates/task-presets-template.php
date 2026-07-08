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
    | Runtime task creation should use CreateTaskAction or
    | CreateTaskFromTemplateAction.
    |
    | due_offset_minutes is the canonical delay field for task templates.
    | due_offset_days may be accepted by legacy/adapted config paths, but new
    | presets should use due_offset_minutes so sub-day task defaults can be
    | represented without adding parallel timing semantics.
    */

    'groups' => [
        'crm_default' => [
            'call_lead',
            'review_lead_notes',
        ],
    ],

    'definitions' => [
        'call_lead' => [
            'title' => 'Call lead',
            'name' => 'Call lead',
            'description' => 'Call the lead and record the outcome.',
            'task_description' => 'Call the lead, record the outcome, and update the contact notes.',
            'priority' => 'normal',
            'responsible_party' => 'internal',
            'assigned_to_strategy' => 'unassigned',
            'source' => 'preset',
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'sales',
            'category' => 'follow_up',
            'is_active' => true,
            'due_offset_minutes' => 1440,
            'related_subject' => [
                'default' => 'current_contact',
            ],
            'defaults' => [
                'due' => [
                    'type' => 'delay',
                    'minutes' => 1440,
                ],
            ],
            'meta' => [],
        ],

        'review_lead_notes' => [
            'title' => 'Review lead notes',
            'name' => 'Review lead notes',
            'description' => 'Review lead history and determine the next best action.',
            'task_description' => null,
            'priority' => 'normal',
            'responsible_party' => 'internal',
            'assigned_to_strategy' => 'unassigned',
            'source' => 'preset',
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'sales',
            'category' => 'review',
            'is_active' => true,
            'due_offset_minutes' => 2880,
            'defaults' => [],
            'meta' => [],
        ],
    ],

];
