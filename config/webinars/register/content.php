<?php

// Registered content

return [

    'title' => 'Register for Webinar',

    'meta_description' => 'Reserve your spot for this live online class.',

    'image_caption' => null,

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
        ],

        'sections' => [
            'notifications' => [
                'title' => 'Notifications',
                'body' => 'Please select at least one method below',
            ],

            'marketing' => [
                'body' => 'The following are not required for registration',
            ],
        ],

        'consent_messages' => [
            'email' => [
                'label' => 'I agree to receive emails related to my registration, including access details, reminders, replay access, and follow-up communications. I may unsubscribe at any time.',
            ],
            'sms' => [
                'label' => 'I agree to receive automated text messages related to my registration, including access details, reminders, replay access, and follow-up communications. Consent is not a condition of registration. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help.',
            ],
        ],
    ],

];
