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
    | Task presets create/update DB-owned TaskTemplate records only.
    | They must not create live Tasks.
    |
    | Runtime Task creation should use CreateTaskAction or
    | CreateTaskFromTemplateAction.
    |
    | due_offset_minutes is the canonical delay field for Task templates.
    | due_offset_days may be accepted by legacy/adapted config paths, but new
    | presets should use due_offset_minutes so sub-day Task defaults can be
    | represented without adding parallel timing semantics.
    */

    /*
    |--------------------------------------------------------------------------
    | Runtime/default precedence
    |--------------------------------------------------------------------------
    |
    | Template-backed live Task creation resolves ordinary Task values in this
    | order:
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
    | task_template_key identity. Historical Tasks must survive TaskTemplate
    | deletion/replacement safely.
    |
    | Durable origin rule:
    |
    | - no-template Tasks are manual only;
    | - template-backed Tasks may be manual or automation-created.
    */

    /*
    |--------------------------------------------------------------------------
    | TaskLink defaults
    |--------------------------------------------------------------------------
    |
    | Live Task relationships use zero-to-many TaskLinks. TaskTemplate presets may
    | declare generic relationship defaults through first-class link_defaults.
    |
    | Initial generic TaskLink roles:
    |
    | - subject
    | - context
    | - result
    |
    | Initial generic link-default sources:
    |
    | - current_contact
    | - current_subject
    |
    | These sources describe generic creation context. They are not module or model
    | inventories, and Tasks must not add sources such as current_pet,
    | current_appointment, current_document, or other module-specific variants.
    |
    | Template-backed creation resolves links in this order:
    |
    | 1. explicit caller-provided live links;
    | 2. TaskTemplate.link_defaults resolved from creation context;
    | 3. merge and de-duplicate by linkable identity plus role.
    |
    | If neither explicit links nor resolvable defaults provide links, the Task may
    | remain unlinked.
    |
    | If a selected link_default requires creation context that is unavailable,
    | fail clearly rather than silently dropping intended relationship context.
    |
    | Do not create competing relationship-default systems through:
    |
    | - related_subject;
    | - defaults.links;
    | - arbitrary relationship IDs in meta.
    |
    | link_defaults is the canonical TaskTemplate relationship-default contract.
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
    | Tasks owns semantic validation of selected TaskTemplate definitions:
    | malformed definitions, stable key identity, invalid responsibility or
    | assignment strategies, due/default shapes, link-default roles/sources, and
    | references that cannot create a safe live Task.
    |
    | FlowRoutes may reference Task templates by stable key. Tasks remains the
    | authority for TaskTemplate definition semantics and DB/runtime availability.
    | The Tasks-owned automation Point-definition contributor validates create_task
    | definitions and referenced TaskTemplate availability; FlowRoutes owns the
    | surrounding Route envelope, handler/capability availability, graph, and runtime
    | consistency.
    |
    */

    'groups' => [
        'default' => [
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
            'source_version' => '2026_07_phase_12',
            'owner_group' => 'sales',
            'category' => 'follow_up',
            'is_active' => true,
            'due_offset_minutes' => 1440,
            'link_defaults' => [
                [
                    'role' => 'subject',
                    'source' => 'current_contact',
                ],
            ],
            'defaults' => [],
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
            'source_version' => '2026_07_phase_12',
            'owner_group' => 'sales',
            'category' => 'review',
            'is_active' => true,
            'due_offset_minutes' => 2880,
            'link_defaults' => [
                [
                    'role' => 'subject',
                    'source' => 'current_contact',
                ],
            ],
            'defaults' => [],
            'meta' => [],
        ],
    ],

];


