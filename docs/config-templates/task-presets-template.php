
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

    /*
    |--------------------------------------------------------------------------
    | Runtime/default precedence
    |--------------------------------------------------------------------------
    |
    | Template-backed live task creation resolves values in this order:
    |
    | 1. explicit caller value
    | 2. first-class TaskTemplate field
    | 3. TaskTemplate.defaults
    | 4. system fallback
    |
    | Use first-class fields for stable concepts such as title, priority,
    | responsible_party, assignment strategy, and due_offset_minutes. Use
    | defaults only for genuine generic fallback data.
    |
    | A live Task may store nullable task_template_id plus durable
    | task_template_key identity. Historical tasks must survive template
    | deletion/replacement safely.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Validation expectations
    |--------------------------------------------------------------------------
    |
    | Tasks owns validation of task preset groups and task template definitions.
    | Validation should catch missing groups, missing definitions, malformed
    | definitions, duplicate/ambiguous stable keys, invalid responsibility or
    | assignment strategies, and references that cannot create a safe live Task.
    |
    | FlowRoutes may reference Task templates by stable key, but Tasks remains the
    | authority for whether that template definition is valid and available.
    |
    */

    'groups' => [
        'crm_default' => [
            'call_contact',
            'review_contact_notes',
        ],
    ],

    'definitions' => [
        'call_contact' => [
            'title' => 'Call contact',
            'name' => 'Call contact',
            'description' => 'Call the contact and record the outcome.',
            'task_description' => 'Call the contact, record the outcome, and update the contact notes.',
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

        'review_contact_notes' => [
            'title' => 'Review contact notes',
            'name' => 'Review contact notes',
            'description' => 'Review contact history and determine the next best action.',
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


