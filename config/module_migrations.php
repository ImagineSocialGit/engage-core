<?php

return [
    'platform' => [
        'path' => 'database/migrations/platform',
    ],

    'modules' => [
        'core' => ['path' => 'database/migrations/modules/core'],
        'relationships' => ['path' => 'database/migrations/modules/relationships'],
        'messaging' => ['path' => 'database/migrations/modules/messaging'],
        'inbound_messaging' => ['path' => 'database/migrations/modules/inbound_messaging'],
        'internal_notifications' => ['path' => 'database/migrations/modules/internal_notifications'],
        'tasks' => ['path' => 'database/migrations/modules/tasks'],
        'scheduling' => ['path' => 'database/migrations/modules/scheduling'],
        'portal' => ['path' => 'database/migrations/modules/portal'],
        'forms' => ['path' => 'database/migrations/modules/forms'],
        'documents' => ['path' => 'database/migrations/modules/documents'],
        'media' => ['path' => 'database/migrations/modules/media'],
        'commerce' => ['path' => 'database/migrations/modules/commerce'],
        'location' => ['path' => 'database/migrations/modules/location'],
        'events' => ['path' => 'database/migrations/modules/events'],
        'workflow' => ['path' => 'database/migrations/modules/workflow'],
        'flow_routes' => ['path' => 'database/migrations/modules/flow_routes'],
        'campaigns' => ['path' => 'database/migrations/modules/campaigns'],
        'broadcasts' => ['path' => 'database/migrations/modules/broadcasts'],
        'webinars' => ['path' => 'database/migrations/modules/webinars'],
        'reporting' => ['path' => 'database/migrations/modules/reporting'],
        'mortgage' => ['path' => 'database/migrations/verticals/mortgage'],
    ],
];