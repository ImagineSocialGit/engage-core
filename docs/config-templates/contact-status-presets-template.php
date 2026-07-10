<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact Status Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first file paths:
    | config/presets/modules/core/contact-statuses.php
    | config/presets/modules/{contributor-module}/contact-statuses.php
    | client/{client-key}/config/presets/modules/{contributor-module}/contact-statuses.php
    |
    | Core owns ContactStatus.
    | ContactStatus is available for manual CRM adjustment even when Workflow,
    | FlowRoutes, Campaigns, Webinars, or Tasks are disabled.
    |
    | Keep statuses generic unless the preset package is explicitly vertical-
    | specific. Internal keys stay contact-neutral. Client-facing names and descriptions may use the configured industry noun when the preset is deliberately product- or vertical-specific.
    |
    | Do not split statuses into manual-only or automation-only categories by default.
    | If a manual status change will trigger selected FlowRoute automation, 
    | the CRM UI should warn the operator before applying the change.
    */

    /*
    |--------------------------------------------------------------------------
    | Sync/customization expectations
    |--------------------------------------------------------------------------
    |
    | ContactStatus rows are DB-owned.
    |
    | Normal sync updates non-customized rows and preserves customized rows.
    | Explicit force sync may overwrite customized rows and clears
    | is_customized/customized_at.
    |
    | Keep first-class status values in first-class fields. Do not duplicate
    | description/category/color/source_version inside meta.
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
    | Core owns semantic validation of selected ContactStatus definitions. Stable
    | internal keys remain contact-neutral. Client-facing labels may use the
    | configured industry noun without changing the canonical key or runtime model.
    |
    | FlowRoutes change-status points may reference these stable keys and should be
    | validated against the selected preset package's available ContactStatus
    | definitions before sync/client handoff.
    |
    */

    'groups' => [
        'crm_default' => [
            'new_contact',
            'attempting_contact',
            'consultation_scheduled',
            'nurture',
            'closed_lost',
        ],
    ],

    'definitions' => [
        'new_contact' => [
            'key' => 'new_contact',
            'name' => 'New Contact',
            'description' => 'A newly-created contact that has not yet been worked.',
            'color' => 'gray',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'attempting_contact' => [
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'description' => 'The team is trying to reach the contact.',
            'color' => 'blue',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'consultation_scheduled' => [
            'key' => 'consultation_scheduled',
            'name' => 'Consultation Scheduled',
            'description' => 'The contact has scheduled a consultation or strategy session.',
            'color' => 'green',
            'sort_order' => 30,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'nurture' => [
            'key' => 'nurture',
            'name' => 'Nurture',
            'description' => 'The contact is not ready now but should remain in follow-up.',
            'color' => 'yellow',
            'sort_order' => 40,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'closed_lost' => [
            'key' => 'closed_lost',
            'name' => 'Closed Lost',
            'description' => 'The contact is no longer active.',
            'color' => 'red',
            'sort_order' => 90,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],
    ],

];
