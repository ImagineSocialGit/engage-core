<?php

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;

return [
    'groups' => [
        'webinar_default' => [
            'webinar.call_high_intent_contact',
            'webinar.review_reply',
        ],
    ],

    'definitions' => [
        'webinar.call_high_intent_contact' => [
                    'name' => 'Call high-intent contact',
                    'title' => 'Call webinar contact',
                    'description' => 'Follow up manually with a high-intent webinar contact.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                    'priority' => 'high',
                    'due_offset_minutes' => 1440,
                    'source_version' => '2026_07_phase_3',
                    'owner_group' => 'webinars',
                    'category' => 'webinar',
                ],

        'webinar.review_reply' => [
                    'name' => 'Review reply',
                    'title' => 'Review inbound reply',
                    'description' => 'Review and respond to a webinar-related inbound reply.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                    'priority' => null,
                    'due_offset_minutes' => 1440,
                    'source_version' => '2026_07_phase_3',
                    'owner_group' => 'webinars',
                    'category' => 'webinar',
                ],
    ],
];
