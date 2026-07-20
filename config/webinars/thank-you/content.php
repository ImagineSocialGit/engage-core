<?php

return [

    'meta_title' => 'Webinar Registration',

    'meta_description' =>
        'View the current status of your webinar registration.',

    'hero' => [
        'enabled' => true,
        'eyebrow' => 'Registration received',
        'title' => 'We’re finishing your registration',
        'body' => 'Your details were saved successfully. This page will update automatically while we finish your webinar access and confirmation details.',
    ],

    'next_steps' => [
        'enabled' => true,
        'heading' => 'What happens next',
        'items' => [],
    ],

    'event_details' => [
        'enabled' => true,
        'heading' => 'Your webinar',
        'items' => [
            [
                'key' => 'date',
                'label' => 'Date',
                'value' => null,
            ],
            [
                'key' => 'time',
                'label' => 'Time',
                'value' => null,
            ],
            [
                'key' => 'location',
                'label' => 'Where',
                'value' => 'Live Online',
            ],
        ],
    ],

    'actions' => [
        [
            'label' => 'View upcoming webinars',
            'route' => 'webinar.index',
            'variant' => 'secondary',
        ],
    ],

    'states' => [
        'processing' => [
            'meta_title' => 'Registration Processing',
            'hero' => [
                'eyebrow' => 'Registration received',
                'title' => 'We’re finishing your registration',
                'body' => 'Your details were saved successfully. This page will update automatically while we finish your webinar access and confirmation details.',
            ],
            'next_steps' => [
                'items' => [
                    [
                        'title' => 'Stay on this page',
                        'body' => 'The registration status will refresh automatically.',
                    ],
                    [
                        'title' => 'Watch your inbox',
                        'body' => 'Confirmation details will arrive after finalization completes.',
                    ],
                    [
                        'title' => 'Do not register again',
                        'body' => 'Your local registration is already saved.',
                    ],
                ],
            ],
        ],

        'confirmed' => [
            'meta_title' => 'Registration Confirmed',
            'meta_description' => 'Your webinar registration is confirmed.',
            'hero' => [
                'eyebrow' => 'You’re registered',
                'title' => 'Your registration is confirmed',
                'body' => 'Check your inbox for confirmation details, webinar access information, and any next steps.',
            ],
            'next_steps' => [
                'items' => [
                    [
                        'title' => 'Watch for confirmation',
                        'body' => 'Check your inbox for access details.',
                    ],
                    [
                        'title' => 'Save the date',
                        'body' => 'Add the session to your calendar.',
                    ],
                    [
                        'title' => 'Join when it starts',
                        'body' => 'Use the access information in your confirmation message.',
                    ],
                ],
            ],
        ],

        'delayed' => [
            'meta_title' => 'Registration Received',
            'hero' => [
                'eyebrow' => 'Registration received',
                'title' => 'Confirmation is taking longer than usual',
                'body' => 'Your details are safely recorded, but final confirmation is delayed. Please do not submit another registration. Watch your inbox for an update.',
            ],
            'next_steps' => [
                'items' => [
                    [
                        'title' => 'No need to register again',
                        'body' => 'A second submission could create conflicting provider records.',
                    ],
                    [
                        'title' => 'Watch your inbox',
                        'body' => 'Confirmation will be sent after the registration is resolved.',
                    ],
                    [
                        'title' => 'Your details are saved',
                        'body' => 'The local registration remains recorded while the issue is reviewed.',
                    ],
                ],
            ],
        ],

        'cancelled' => [
            'meta_title' => 'Registration Cancelled',
            'hero' => [
                'eyebrow' => 'Registration cancelled',
                'title' => 'This registration is no longer active',
                'body' => 'The webinar registration associated with this link has been cancelled.',
            ],
            'next_steps' => [
                'enabled' => false,
                'items' => [],
            ],
        ],
    ],

];