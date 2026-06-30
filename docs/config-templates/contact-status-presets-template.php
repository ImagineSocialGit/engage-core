<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact Status Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets/contact-statuses.php
    | client/{client-key}/config/presets/contact-statuses.php, if client override exists
    |
    | Core owns ContactStatus.
    | ContactStatus is available for manual CRM adjustment even when Workflow,
    | FlowRoutes, Campaigns, Webinars, or Tasks are disabled.
    |
    | Keep statuses generic unless the preset package is explicitly vertical-
    | specific. Use lead/leads in CRM/client-facing names and descriptions.
    */

    'groups' => [
        'crm_default' => [
            'new_lead',
            'attempting_contact',
            'consultation_scheduled',
            'nurture',
            'closed_lost',
        ],
    ],

    'definitions' => [
        'new_lead' => [
            'key' => 'new_lead',
            'name' => 'New Lead',
            'description' => 'A newly-created lead that has not yet been worked.',
            'color' => 'gray',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'attempting_contact' => [
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'description' => 'The team is trying to reach the lead.',
            'color' => 'blue',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'consultation_scheduled' => [
            'key' => 'consultation_scheduled',
            'name' => 'Consultation Scheduled',
            'description' => 'The lead has scheduled a consultation or strategy session.',
            'color' => 'green',
            'sort_order' => 30,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'nurture' => [
            'key' => 'nurture',
            'name' => 'Nurture',
            'description' => 'The lead is not ready now but should remain in follow-up.',
            'color' => 'yellow',
            'sort_order' => 40,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],

        'closed_lost' => [
            'key' => 'closed_lost',
            'name' => 'Closed Lost',
            'description' => 'The lead is no longer active.',
            'color' => 'red',
            'sort_order' => 90,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [],
        ],
    ],

];