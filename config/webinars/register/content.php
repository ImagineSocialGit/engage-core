<?php

// Registered content

return [

    'title' => 'Register for Webinar',

    'meta_description' => 'Reserve your spot for this live online class.',

    'image_caption' => null,

    'sections' => [
        'notifications' => [
            'title' => 'Webinar Updates',
            'body' => 'Choose at least one way to receive access details and reminders.',
        ],

        'marketing' => [
            'title' => 'Keep Learning — Optional',
            'body' => 'Get future tips, resources, and updates after the webinar.',
        ],
    ],

    'fields' => [
        'first_name' => [
            'label' => 'First name',
            'placeholder' => 'First name',
        ],

        'last_name' => [
            'label' => 'Last name',
            'placeholder' => 'Last name',
        ],

        'email' => [
            'label' => 'Email address',
            'placeholder' => 'you@example.com',
        ],

        'phone' => [
            'label' => 'Mobile phone',
            'placeholder' => '(555) 555-5555',
            'helper' => 'Required only when you choose an SMS option.',
        ],

        'consent_messages' => [
            'email' => [
                'label' => 'Webinar email',
            ],
            'sms' => [
                'label' => 'Webinar SMS',
                'disclosure' => 'By selecting Webinar SMS, you agree to receive automated texts about this webinar, including access details, reminders, replay availability, and related follow-up. Consent is not a condition of registration. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help.',
            ],
        ],

        'marketing_consent_messages' => [
            'email' => [
                'label' => 'Marketing email',
            ],
            'sms' => [
                'label' => 'Marketing SMS',
                'disclosure' => 'By selecting Marketing SMS, you agree to receive automated marketing texts. Consent is not a condition of registration. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help.',
            ],
        ],
    ],

    'legal_links' => [
        'enabled' => false,
        'intro' => 'By registering, you agree to our',
        'links' => [],
    ],

];
