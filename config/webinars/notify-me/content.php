<?php

return [
    'title' => 'Get Webinar Updates',
    'meta_description' => 'Get notified when the next webinar session is scheduled.',

    'hero' => [
        'enabled' => true,
        'eyebrow' => 'New Class Dates Coming Soon',
        'title_prefix' => 'Get notified about ',
        'body' => 'This webinar series does not have a scheduled session right now.',
        'supporting_copy' => [
            'Join the notification list and we’ll let you know as soon as the next class date is available.',
        ],
        'bullets' => [
            'intro' => 'You’ll be first to know when:',
            'list' => [
                'A new live session is scheduled',
                'Registration opens for this topic',
                'Replay or follow-up resources become available',
            ],
        ],
    ],

    'form' => [
        'route' => 'webinar.notify-me.store',
    ],

    'form_card' => [
        'enabled' => true,
        'title' => 'Get notified',
        'body' => 'Enter your information below and we’ll notify you when this webinar series is scheduled.',
        'helper_text' => 'No spam. Just updates for this webinar series.',
    ],

    'fields' => [
        'first_name' => [
            'label' => 'First Name',
            'placeholder' => 'Enter your first name',
        ],
        'last_name' => [
            'label' => 'Last Name',
            'placeholder' => 'Enter your last name',
        ],
        'email' => [
            'label' => 'Email Address',
            'placeholder' => 'Enter your email address',
        ],
        'phone' => [
            'label' => 'Phone Number',
            'placeholder' => 'Enter your phone number',
        ],
        'consent_messages' => [
            'label' => 'I agree to receive automated email and text messages related to webinar scheduling updates, registration availability, reminders, replay access, and follow-up communications. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help. Consent is not a condition of purchase.',
        ],
    ],

    'submit' => [
        'label' => 'Notify Me When Scheduled',
    ],

    'compliance' => [
        'enabled' => true,
        'text' => 'This is an educational class notification list. No application is required. Loan approval is subject to credit, income, assets, and underwriting guidelines.',
    ],
];