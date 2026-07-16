<?php

// Registered content

return [

    'title' => 'Register for Webinar',

    'meta_description' => 'Reserve your spot for this live online class.',

    'image_caption' => null,

    'registration' => [
        'consent_header' => [
            'enabled' => true,
            'title' => 'Almost Done!',
            'body' => "We'll send you:",
            'items' => [
                'Your Zoom link',
                'Webinar reminders',
                "Replay access if you can't attend live",
            ],
        ],
        'sections' => [
            'notifications' => [
                'title' => 'Webinar Registration (Required)',
            ],

            'marketing' => [
                'title' => 'Stay Connected (Optional)',
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
                'helper' => 'Required to receive SMS.',
            ],

            'consent_messages' => [
                'email' => [
                    'label' => 'Email me my webinar confirmation, access link, reminders, replay, and webinar-related updates.',
                    'helper' => 'You may unsubscribe at any time.',
                ],
                'sms' => [
                    'label' => 'Text me webinar reminders and access information. (Optional)',
                    'disclosure' => 'By checking this box, you agree to receive automated text messages related to this webinar. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                ],
            ],

            'marketing_consent_messages' => [
                'email' => [
                    'label' => 'Send me marketing emails for future webinars, educational content, and related updates.',
                ],
                'sms' => [
                    'label' => 'Send me marketing texts for future webinars, educational content, and related updates.',
                    'disclosure' => 'Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                ],
            ],
        ],

        'legal_links' => [
            'enabled' => false,
            'intro' => 'By registering, you agree to our',
            'links' => [],
        ],
    ],

];
