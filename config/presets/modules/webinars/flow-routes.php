<?php

return [
    'groups' => [
        'webinar_default' => [
            'webinar_attended_status_transition',
            'webinar_missed_status_transition',
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],
    ],

    'definitions' => [
        'webinar_attended_status_transition' => [
            'event_key' => 'webinar.attended',
            'name' => 'Webinar Attended Status Transition',
            'description' => 'Move contacts who attended a webinar into the attended_webinar workflow status.',
            'source_version' => 'client_readiness_2026_07',
            'category' => 'webinar',
            'role' => 'status_transition',
            'points' => [
                'change_status_to_attended_webinar' => [
                    'type' => 'change_status',
                    'name' => 'Change Status to Attended Webinar',
                    'description' => 'Move the contact into the attended_webinar status after a webinar.attended event.',
                    'definition' => [
                        'contact_status_key' => 'attended_webinar',
                    ],
                ],
            ],
        ],

        'webinar_missed_status_transition' => [
            'event_key' => 'webinar.missed',
            'name' => 'Webinar Missed Status Transition',
            'description' => 'Move contacts who missed a webinar into the missed_webinar workflow status.',
            'source_version' => 'client_readiness_2026_07',
            'category' => 'webinar',
            'role' => 'status_transition',
            'points' => [
                'change_status_to_missed_webinar' => [
                    'type' => 'change_status',
                    'name' => 'Change Status to Missed Webinar',
                    'description' => 'Move the contact into the missed_webinar status after a webinar.missed event.',
                    'definition' => [
                        'contact_status_key' => 'missed_webinar',
                    ],
                ],
            ],
        ],

        'webinar_attended_campaign_enrollment' => [
            'event_key' => 'webinar.attended',
            'name' => 'Webinar Attended Follow-Up',
            'description' => 'Enroll contacts who attended a webinar into the attended nurture campaign.',
            'source_version' => 'phase_19_default',
            'category' => 'webinar',
            'role' => 'campaign_enrollment',
            'points' => [
                'enroll_webinar_attended_nurture' => [
                    'type' => 'enroll_campaign',
                    'name' => 'Enroll Webinar Attended Nurture',
                    'description' => 'Enroll the contact into the attended webinar nurture campaign.',
                    'definition' => [
                        'campaign_key' => 'webinar_attended_nurture',
                    ],
                ],
            ],
        ],

        'webinar_missed_campaign_enrollment' => [
            'event_key' => 'webinar.missed',
            'name' => 'Webinar Missed Follow-Up',
            'description' => 'Enroll contacts who missed a webinar into the missed webinar nurture campaign.',
            'source_version' => 'phase_19_default',
            'category' => 'webinar',
            'role' => 'campaign_enrollment',
            'points' => [
                'enroll_webinar_missed_nurture' => [
                    'type' => 'enroll_campaign',
                    'name' => 'Enroll Webinar Missed Nurture',
                    'description' => 'Enroll the contact into the missed webinar nurture campaign.',
                    'definition' => [
                        'campaign_key' => 'webinar_missed_nurture',
                    ],
                ],
            ],
        ],
    ],
];