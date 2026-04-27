<?php

return [
    'title' => 'Registration Complete',
    'meta_description' => 'Your webinar registration has been received.',

    'card' => [
        'align' => 'text-center',
        'eyebrow' => 'You Are Registered',
        'title' => 'Your seat is confirmed',
        'body' => 'Thanks for registering. Keep an eye on your email and phone for confirmation details, reminders, and access information for the webinar.',
    ],

    'actions' => [
        [
            'label' => 'Back to Webinars',
            'route' => 'webinar.index',
            'variant' => 'primary',
        ],
        [
            'label' => 'View Other Sessions',
            'route' => 'webinar.index',
            'variant' => 'secondary',
        ],
    ],

    'blocks' => [
        'card',
        'actions',
    ],
];