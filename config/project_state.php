<?php

return [
    'format' => 'engage-core-project-state',
    'version' => 6,

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
    'enforce_client_key' => true,

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
    'sections' => [
        'core' => require __DIR__.'/project_state/core.php',
        'messaging' => require __DIR__.'/project_state/messaging.php',
        'webinars' => require __DIR__.'/project_state/webinars.php',
        'tasks' => require __DIR__.'/project_state/tasks.php',
        'campaigns' => require __DIR__.'/project_state/campaigns.php',
        'broadcasts' => require __DIR__.'/project_state/broadcasts.php',
        'workflow' => require __DIR__.'/project_state/workflow.php',
        'automation_events' => require __DIR__.'/project_state/automation_events.php',
    ],
];