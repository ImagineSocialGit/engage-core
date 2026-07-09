
<?php

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;

return [

    'groups' => [
        'general_default' => [
            'general.follow_up',
            'general.review',
            'general.waiting_on_contact',
            'general.waiting_on_third_party',
        ],

        'task_workspace_default' => [
            'task_workspace.follow_up',
            'task_workspace.review_item',
            'task_workspace.waiting_on_someone',
        ],

        'webinar_default' => [
            'webinar.call_high_intent_contact',
            'webinar.review_reply',
        ],

        'mortgage_default' => [
            'mortgage.call_contact',
            'mortgage.contact_documents',
            'mortgage.review_application',
            'mortgage.waiting_on_realtor',
            'mortgage.waiting_on_vendor',
        ],
    ],

    'definitions' => [
        'general.follow_up' => [
            'name' => 'Follow up',
            'title' => 'Follow up',
            'description' => 'General follow-up task.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 1440,
            'source_version' => '2026_07_phase_3',
            'category' => 'general',
        ],

        'general.review' => [
            'name' => 'Review',
            'title' => 'Review details',
            'description' => 'Review a contact, request, file, or manual item.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 2880,
            'source_version' => '2026_07_phase_3',
            'category' => 'general',
        ],

        'general.waiting_on_contact' => [
            'name' => 'Waiting on contact',
            'title' => 'Contact needs to provide something',
            'description' => 'Track something the contact needs to provide or complete.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 4320,
            'source_version' => '2026_07_phase_3',
            'category' => 'general',
            'related_subject' => [
                'default' => 'current_contact',
            ],
        ],

        'general.waiting_on_third_party' => [
            'name' => 'Waiting on third party',
            'title' => 'Third party needs to complete something',
            'description' => 'Track a dependency owned by a vendor, partner, or other third party.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 4320,
            'source_version' => '2026_07_phase_3',
            'category' => 'general',
        ],

        'task_workspace.follow_up' => [
            'name' => 'Follow up',
            'title' => 'Follow up',
            'description' => 'A simple follow-up task.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 1440,
            'source_version' => '2026_07_phase_3',
            'category' => 'task_workspace',
        ],

        'task_workspace.review_item' => [
            'name' => 'Review item',
            'title' => 'Review item',
            'description' => 'Review a manual item or dependency.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 2880,
            'source_version' => '2026_07_phase_3',
            'category' => 'task_workspace',
        ],

        'task_workspace.waiting_on_someone' => [
            'name' => 'Waiting on someone',
            'title' => 'Waiting on someone',
            'description' => 'Track something that depends on another person.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_UNKNOWN,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 4320,
            'source_version' => '2026_07_phase_3',
            'category' => 'task_workspace',
        ],

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

        'mortgage.call_contact' => [
            'name' => 'Call contact',
            'title' => 'Call contact',
            'description' => 'Call the contact for next-step follow-up.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 1440,
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'mortgage',
            'category' => 'mortgage',
        ],

        'mortgage.contact_documents' => [
            'name' => 'Contact documents',
            'title' => 'Contact needs to provide documents',
            'description' => 'Track documents or information needed from the contact.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => 'high',
            'due_offset_minutes' => 2880,
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'mortgage',
            'category' => 'mortgage',
            'related_subject' => [
                'default' => 'current_contact',
            ],
        ],

        'mortgage.review_application' => [
            'name' => 'Review application',
            'title' => 'Review contact application',
            'description' => 'Review contact application details.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 1440,
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'mortgage',
            'category' => 'mortgage',
        ],

        'mortgage.waiting_on_realtor' => [
            'name' => 'Waiting on realtor',
            'title' => 'Realtor needs to provide information',
            'description' => 'Track a realtor-owned dependency.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 4320,
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'mortgage',
            'category' => 'mortgage',
        ],

        'mortgage.waiting_on_vendor' => [
            'name' => 'Waiting on vendor',
            'title' => 'Vendor needs to complete item',
            'description' => 'Track a title, appraisal, inspection, or other vendor dependency.',
            'task_description' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'priority' => null,
            'due_offset_minutes' => 4320,
            'source_version' => '2026_07_phase_3',
            'owner_group' => 'mortgage',
            'category' => 'mortgage',
        ],
    ],

];
