<?php

$sections = [
    'core' => require __DIR__.'/project_state/core.php',
    'scheduling' => require __DIR__.'/project_state/scheduling.php',
    'relationships' => require __DIR__.'/project_state/relationships.php',
    'location' => require __DIR__.'/project_state/location.php',
    'mortgage' => require __DIR__.'/project_state/mortgage.php',
    'internal_notifications' => require __DIR__.'/project_state/internal_notifications.php',
    'inbound_messaging' => require __DIR__.'/project_state/inbound_messaging.php',
    'messaging' => require __DIR__.'/project_state/messaging.php',
    'webinars' => require __DIR__.'/project_state/webinars.php',
    'tasks' => require __DIR__.'/project_state/tasks.php',
    'campaigns' => require __DIR__.'/project_state/campaigns.php',
    'broadcasts' => require __DIR__.'/project_state/broadcasts.php',
    'workflow' => require __DIR__.'/project_state/workflow.php',
    'automation_opportunities' => require __DIR__.'/project_state/automation_opportunities.php',
    'automation_events' => require __DIR__.'/project_state/automation_events.php',
    'flow_routes' => require __DIR__.'/project_state/flow_routes.php',
    'reporting' => require __DIR__.'/project_state/reporting.php',
];

$sectionVersions = array_map(
    static fn (array $section): int => (int) ($section['version'] ?? 0),
    $sections,
);

return [
    'format' => 'engage-core-project-state',

    /*
    |--------------------------------------------------------------------------
    | Derived document version
    |--------------------------------------------------------------------------
    |
    | This is a human-readable compatibility label only. Never increment it by
    | hand. Every owning section keeps its own monotonically increasing version;
    | the root value is their sum, so independent section bumps compose cleanly
    | when branches merge. The contract fingerprint is the authoritative exact
    | compatibility identity and prevents version-sum collisions.
    |
    */
    'version' => array_sum($sectionVersions),

    /*
    |--------------------------------------------------------------------------
    | Owner-only access
    |--------------------------------------------------------------------------
    |
    | The project-state surface is intentionally unavailable until an exact CRM
    | user email is configured. Keep this value in the selected client's .env.
    |
    */
    'authorized_email' => env('PROJECT_STATE_ADMIN_EMAIL'),

    'max_upload_kilobytes' => 102400,
    'resume_batch_size' => 500,
    'enforce_client_key' => true,

    'schema_ignored_tables' => [
        'migrations',
        'sqlite_sequence',
    ],

    'table_policies' => require __DIR__.'/project_state/table_policies.php',

    /*
    |--------------------------------------------------------------------------
    | Current-format state sections
    |--------------------------------------------------------------------------
    |
    | Section order is dependency-safe import order. Each section file owns its
    | current JSON/table contract. Older exports are transformed externally
    | before upload; this application imports only the current format.
    |
    */
    'sections' => $sections,
];