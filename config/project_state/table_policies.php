<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Explicit policy for every table outside the exported section contract
    |--------------------------------------------------------------------------
    |
    | Exported tables are derived from project_state.sections. Every other
    | application table must appear here so new migrations cannot become silent
    | omissions.
    |
    | Modes:
    | - environment_owned: deployment/account state intentionally stays local.
    | - resettable: local history or coordination state intentionally resets.
    | - must_be_empty: export is blocked while any row exists.
    | - terminal_only: rows may exist only in the listed terminal states.
    |
    */

    'users' => [
        'mode' => 'environment_owned',
        'reason' => 'CRM accounts and credentials are recreated per environment.',
    ],
    'password_reset_tokens' => [
        'mode' => 'environment_owned',
        'reason' => 'Password-reset credentials are environment-local and short-lived.',
    ],
    'sessions' => [
        'mode' => 'environment_owned',
        'reason' => 'Authenticated sessions must not cross environments.',
    ],

    'cache' => [
        'mode' => 'resettable',
        'reason' => 'Framework cache state is rebuilt locally.',
    ],
    'cache_locks' => [
        'mode' => 'resettable',
        'reason' => 'Framework cache locks are runtime coordination only.',
    ],
    'job_batches' => [
        'mode' => 'resettable',
        'reason' => 'Framework batch history is not client domain state.',
    ],
    'failed_jobs' => [
        'mode' => 'resettable',
        'reason' => 'Failed-job history remains an environment-local operational record.',
    ],
    'jobs' => [
        'mode' => 'must_be_empty',
        'reason' => 'Database-backed queued work must be drained before the final export.',
    ],

    'dashboard_acknowledgements' => [
        'mode' => 'resettable',
        'reason' => 'Per-user dashboard acknowledgement state intentionally resets.',
    ],
    'project_state_resume_items' => [
        'mode' => 'resettable',
        'reason' => 'Resume rows coordinate only the current target import.',
    ],
    'module_installations' => [
        'mode' => 'environment_owned',
        'reason' => 'Module installation and migration bookkeeping belongs to the target environment.',
    ],


    'reporting_sessions' => [
        'mode' => 'resettable',
        'reason' => 'Ephemeral first-party Reporting session correlation intentionally resets during Project State rebuilds.',
    ],
    'reporting_observations' => [
        'mode' => 'resettable',
        'reason' => 'Privacy-limited raw Reporting observations intentionally reset; retained daily aggregates transfer separately.',
    ],
    'reporting_projection_checkpoints' => [
        'mode' => 'resettable',
        'reason' => 'Reporting projection checkpoints are local derived-work coordination and intentionally reset during Project State rebuilds.',
    ],


    'inbound_message_receipts' => [
        'mode' => 'terminal_only',
        'column' => 'status',
        'values' => ['completed'],
        'reason' => 'Inbound receipt processing must be fully completed before export.',
    ],
    'webhook_inbox_receipts' => [
        'mode' => 'terminal_only',
        'column' => 'status',
        'values' => ['completed'],
        'reason' => 'Webhook claims and retries must be fully completed before export.',
    ],
    'bookable_slot_offers' => [
        'mode' => 'must_be_empty',
        'reason' => 'Ephemeral slot offers must be cleared before export.',
    ],
    'booking_holds' => [
        'mode' => 'must_be_empty',
        'reason' => 'Booking holds must expire or be resolved before export.',
    ],

    'locations' => [
        'mode' => 'must_be_empty',
        'reason' => 'Location transfer support has not been added yet.',
    ],
    'contact_locations' => [
        'mode' => 'must_be_empty',
        'reason' => 'Location transfer support has not been added yet.',
    ],


    'portal_users' => [
        'mode' => 'must_be_empty',
        'reason' => 'Portal transfer support has not been added yet.',
    ],
    'portal_contact_links' => [
        'mode' => 'must_be_empty',
        'reason' => 'Portal transfer support has not been added yet.',
    ],
    'portal_invitations' => [
        'mode' => 'must_be_empty',
        'reason' => 'Portal transfer support has not been added yet.',
    ],
    'portal_access_grants' => [
        'mode' => 'must_be_empty',
        'reason' => 'Portal transfer support has not been added yet.',
    ],

    'form_definitions' => [
        'mode' => 'must_be_empty',
        'reason' => 'Forms transfer support has not been added yet.',
    ],
    'form_versions' => [
        'mode' => 'must_be_empty',
        'reason' => 'Forms transfer support has not been added yet.',
    ],
    'form_submissions' => [
        'mode' => 'must_be_empty',
        'reason' => 'Forms transfer support has not been added yet.',
    ],
    'form_submission_values' => [
        'mode' => 'must_be_empty',
        'reason' => 'Forms transfer support has not been added yet.',
    ],

    'document_requirement_definitions' => [
        'mode' => 'must_be_empty',
        'reason' => 'Documents transfer support has not been added yet.',
    ],
    'document_requests' => [
        'mode' => 'must_be_empty',
        'reason' => 'Documents transfer support has not been added yet.',
    ],
    'document_uploads' => [
        'mode' => 'must_be_empty',
        'reason' => 'Documents transfer support has not been added yet.',
    ],
    'document_review_events' => [
        'mode' => 'must_be_empty',
        'reason' => 'Documents transfer support has not been added yet.',
    ],

    'events' => [
        'mode' => 'must_be_empty',
        'reason' => 'Events transfer support has not been added yet.',
    ],
    'event_external_references' => [
        'mode' => 'must_be_empty',
        'reason' => 'Events transfer support has not been added yet.',
    ],

    'commerce_customers' => [
        'mode' => 'must_be_empty',
        'reason' => 'Commerce transfer support has not been added yet.',
    ],
    'commerce_products' => [
        'mode' => 'must_be_empty',
        'reason' => 'Commerce transfer support has not been added yet.',
    ],
    'commerce_orders' => [
        'mode' => 'must_be_empty',
        'reason' => 'Commerce transfer support has not been added yet.',
    ],
    'commerce_order_items' => [
        'mode' => 'must_be_empty',
        'reason' => 'Commerce transfer support has not been added yet.',
    ],
    'commerce_order_events' => [
        'mode' => 'must_be_empty',
        'reason' => 'Commerce transfer support has not been added yet.',
    ],
];