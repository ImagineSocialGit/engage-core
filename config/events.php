<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Events Definition Registry
    |--------------------------------------------------------------------------
    |
    | These definitions provide the universal Events vocabulary installed by
    | Core. Optional modules and client packages may contribute additional
    | definitions through EventDefinitionContributor without changing Events
    | persistence or importing their private runtime classes.
    |
    */

    'definitions' => [
        'event_type' => [
            'concert' => [
                'label' => 'Concert',
                'description' => 'A live music performance occurrence.',
                'sort_order' => 10,
            ],
            'seminar' => [
                'label' => 'Seminar',
                'description' => 'A focused educational or informational occurrence.',
                'sort_order' => 20,
            ],
            'conference' => [
                'label' => 'Conference',
                'description' => 'A conference occurrence represented as one Event.',
                'sort_order' => 30,
            ],
            'open_house' => [
                'label' => 'Open House',
                'description' => 'A scheduled open-house occurrence.',
                'sort_order' => 40,
            ],
            'livestream' => [
                'label' => 'Livestream',
                'description' => 'A virtual live occurrence hosted outside Engage Core.',
                'sort_order' => 50,
            ],
            'community_event' => [
                'label' => 'Community Event',
                'description' => 'A general community-facing occurrence.',
                'sort_order' => 60,
            ],
        ],

        'stakeholder_role' => [
            'agent' => [
                'label' => 'Agent',
                'sort_order' => 10,
            ],
            'promoter' => [
                'label' => 'Promoter',
                'sort_order' => 20,
            ],
            'venue_contact' => [
                'label' => 'Venue Contact',
                'sort_order' => 30,
            ],
            'tour_manager' => [
                'label' => 'Tour Manager',
                'sort_order' => 40,
            ],
            'production_manager' => [
                'label' => 'Production Manager',
                'sort_order' => 50,
            ],
        ],

        'external_reference_provider' => [
            'website' => [
                'label' => 'Website',
                'description' => 'A general first-party or third-party website.',
                'sort_order' => 10,
            ],
            'youtube' => [
                'label' => 'YouTube',
                'sort_order' => 20,
            ],
            'maps' => [
                'label' => 'Maps',
                'description' => 'A mapping or directions provider.',
                'sort_order' => 30,
            ],
        ],

        'external_reference_type' => [
            'public_page' => [
                'label' => 'Public Page',
                'sort_order' => 10,
            ],
            'event_page' => [
                'label' => 'Event Page',
                'sort_order' => 20,
            ],
            'listing' => [
                'label' => 'Listing',
                'sort_order' => 30,
            ],
            'livestream' => [
                'label' => 'Livestream',
                'sort_order' => 40,
            ],
            'recording' => [
                'label' => 'Recording',
                'sort_order' => 50,
            ],
            'ticket_page' => [
                'label' => 'Ticket Page',
                'sort_order' => 60,
            ],
            'vip_page' => [
                'label' => 'VIP Page',
                'sort_order' => 70,
            ],
            'directions' => [
                'label' => 'Directions',
                'sort_order' => 80,
            ],
        ],

        'attendance_source' => [
            'operator' => [
                'label' => 'Operator',
                'description' => 'An authorized Engage Core operator observation.',
                'sort_order' => 10,
            ],
            'import' => [
                'label' => 'Import',
                'description' => 'An explicitly imported attendance reconciliation.',
                'sort_order' => 20,
            ],
        ],
    ],

];