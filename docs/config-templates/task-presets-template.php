<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Template Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first file paths:
    | config/presets/modules/tasks/tasks.php
    | config/presets/modules/{contributor-module}/tasks.php
    | client/{client-key}/config/presets/modules/{contributor-module}/tasks.php
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
    | Durable origin rule:
    |
    | - no-template Tasks are manual only;
    | - template-backed Tasks may be manual or automation-created.
    |
    | Task relationships are moving to zero-to-many TaskLinks with the generic
    | roles subject, context, and result. Do not add new related_subject examples
    | here until the Tasks-owned TaskLink preset/default contract is implemented.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Validation expectations
    |--------------------------------------------------------------------------
    |
    | Shared preset-composition validation owns package/group/definition structure,
    | including missing selected groups and duplicate contributed group/definition
    | keys.
    |
    | Tasks owns semantic validation of selected Task template definitions:
    | malformed definitions, stable key identity, invalid responsibility or
    | assignment strategies, due/default shapes, and references that cannot create
    | a safe live Task.
    |
    | FlowRoutes may reference Task templates by stable key. Tasks remains the
    | authority for Task template definition semantics and DB/runtime availability,
    | while FlowRoutes owns validation of its own create_task reference.
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


