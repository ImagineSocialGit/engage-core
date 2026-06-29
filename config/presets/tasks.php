<?php

use App\Modules\Tasks\Models\Task;

return [

    'groups' => [

        'general_default' => [
            'templates' => [
                [
                    'key' => 'general.follow_up',
                    'name' => 'Follow up',
                    'title' => 'Follow up',
                    'description' => 'General follow-up task.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 1,
                ],

                [
                    'key' => 'general.review',
                    'name' => 'Review',
                    'title' => 'Review details',
                    'description' => 'Review a contact, request, file, or manual item.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 2,
                ],

                [
                    'key' => 'general.waiting_on_contact',
                    'name' => 'Waiting on contact',
                    'title' => 'Contact needs to provide something',
                    'description' => 'Track something the contact needs to provide or complete.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
                    'priority' => null,
                    'due_offset_days' => 3,
                ],

                [
                    'key' => 'general.waiting_on_third_party',
                    'name' => 'Waiting on third party',
                    'title' => 'Third party needs to complete something',
                    'description' => 'Track a dependency owned by a vendor, partner, or other third party.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
                    'priority' => null,
                    'due_offset_days' => 3,
                ],
            ],
        ],

        'task_workspace_default' => [
            'templates' => [
                [
                    'key' => 'task_workspace.follow_up',
                    'name' => 'Follow up',
                    'title' => 'Follow up',
                    'description' => 'A simple follow-up task.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 1,
                ],

                [
                    'key' => 'task_workspace.review_item',
                    'name' => 'Review item',
                    'title' => 'Review item',
                    'description' => 'Review a manual item or dependency.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 2,
                ],

                [
                    'key' => 'task_workspace.waiting_on_someone',
                    'name' => 'Waiting on someone',
                    'title' => 'Waiting on someone',
                    'description' => 'Track something that depends on another person.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_UNKNOWN,
                    'priority' => null,
                    'due_offset_days' => 3,
                ],
            ],
        ],

        'webinar_default' => [
            'templates' => [
                [
                    'key' => 'webinar.call_hot_lead',
                    'name' => 'Call hot lead',
                    'title' => 'Call webinar lead',
                    'description' => 'Follow up manually with a high-intent webinar lead.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => 'high',
                    'due_offset_days' => 1,
                ],

                [
                    'key' => 'webinar.review_reply',
                    'name' => 'Review reply',
                    'title' => 'Review inbound reply',
                    'description' => 'Review and respond to a webinar-related inbound reply.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 1,
                ],
            ],
        ],

        'mortgage_default' => [
            'templates' => [
                [
                    'key' => 'mortgage.call_lead',
                    'name' => 'Call lead',
                    'title' => 'Call lead',
                    'description' => 'Call the lead for next-step follow-up.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 1,
                ],

                [
                    'key' => 'mortgage.lead_documents',
                    'name' => 'Lead documents',
                    'title' => 'Lead needs to provide documents',
                    'description' => 'Track documents or information needed from the lead.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
                    'priority' => 'high',
                    'due_offset_days' => 2,
                ],

                [
                    'key' => 'mortgage.review_application',
                    'name' => 'Review application',
                    'title' => 'Review lead application',
                    'description' => 'Review lead application details.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                    'priority' => null,
                    'due_offset_days' => 1,
                ],

                [
                    'key' => 'mortgage.waiting_on_realtor',
                    'name' => 'Waiting on realtor',
                    'title' => 'Realtor needs to provide information',
                    'description' => 'Track a realtor-owned dependency.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
                    'priority' => null,
                    'due_offset_days' => 3,
                ],

                [
                    'key' => 'mortgage.waiting_on_vendor',
                    'name' => 'Waiting on vendor',
                    'title' => 'Vendor needs to complete item',
                    'description' => 'Track a title, appraisal, inspection, or other vendor dependency.',
                    'task_description' => null,
                    'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
                    'priority' => null,
                    'due_offset_days' => 3,
                ],
            ],
        ],

    ],

];