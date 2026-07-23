<?php

// Shared registration-page content.
// Client configuration may replace shared defaults. Series metadata and series files
// may replace only sections explicitly enabled by series_overrides below.

return [

    'title' => 'Register for Webinar',
    'meta_description' => 'Reserve your spot for this live online class.',

    'header' => [
        'primary_link' => [
            'label' => 'Reserve Spot',
            'route' => 'webinar.index',
        ],
    ],

    'series_overrides' => [
        'landing' => [
            'title' => true,
            'meta_description' => true,
            'hero' => true,
            'urgency_stats' => true,
            'webinar_title' => true,
            'primary_cta' => true,
            'event_details' => true,
            'problem' => true,
            'instructor' => true,
            'secondary_cta' => true,
            'trust' => true,
            'final_close' => true,
            'compliance' => true,
            'sticky_desktop' => true,
            'sticky_mobile' => true,
        ],
        'registration' => [
            'questions_section' => true,
            'questions' => true,
        ],
    ],

    'hero' => [
        'enabled' => true,
        'eyebrow' => 'Live Online Class',
        'title_prefix' => null,
        'title' => 'Build a Better Plan Before You Make Your Next Move',
        'body' => 'Learn the key information, decisions, and next steps for this topic before you move forward.',
        'supporting_copy' => [],
        'class_details' => [
            'title' => null,
            'bullets' => [],
        ],
        'bullets' => [
            'intro' => 'In this live class, you’ll learn how to:',
            'list' => [
                'Understand the most important decisions',
                'Identify potential issues and opportunities early',
                'Ask better questions before taking the next step',
                'Move forward with a clearer plan',
            ],
        ],
        'image' => null,
    ],

    'urgency_stats' => [
        'enabled' => false,
        'intro' => null,
        'items' => [],
        'closing_line' => null,
    ],

    'webinar_title' => [
        'enabled' => true,
    ],

    'primary_cta' => [
        'enabled' => true,
        'pretext' => 'Next class starts in:',
        'label' => 'Reserve My Free Seat',
        'mobile_label' => 'Reserve My Free Seat',
        'desktop_header_label' => 'Reserve Spot',
        'route' => 'webinar.show',
        'helper_text' => 'Free live training',
    ],

    'countdown' => [
        'enabled' => true,
        'items' => [
            [
                'method' => 'days',
                'label' => 'Days',
            ],
            [
                'method' => 'hours',
                'label' => 'Hrs',
            ],
            [
                'method' => 'minutes',
                'label' => 'Min',
            ],
            [
                'method' => 'seconds',
                'label' => 'Sec',
            ],
        ],
    ],

    'event_details' => [
        'enabled' => true,
        'heading' => null,
        'items' => [
            [
                'key' => 'date',
                'label' => 'Date',
                'value' => null,
                'icon' => 'calendar',
            ],
            [
                'key' => 'time',
                'label' => 'Time',
                'value' => null,
                'icon' => 'clock',
            ],
            [
                'key' => 'location',
                'label' => 'Where',
                'value' => 'Live Online',
                'icon' => 'map_pin',
            ],
        ],
    ],

    'problem' => [
        'enabled' => true,
        'eyebrow' => 'Why Planning First Matters',
        'heading' => 'The best time to find a problem—or an opportunity—is before you commit.',
        'body' => [],
        'bullets' => [
            'intro' => null,
            'list' => [],
        ],
    ],

    'instructor' => [
        'enabled' => false,
        'image' => null,
        'image_alt' => 'Webinar instructor',
        'image_sizes' => '(min-width: 1024px) 34vw, 90vw',
        'image_caption' => null,
        'eyebrow' => 'Your Instructor',
        'heading' => 'Meet Your Instructor',
        'body' => [],
        'credibility' => [],
    ],

    'registration' => [
        'questions_section' => [
            'enabled' => true,
            'title' => 'Help us tailor the class',
        ],
        'questions' => [],
        'consents' => [
            'transactional' => [
                'email' => true,
                'sms' => true,
            ],
            'marketing' => [
                'email' => true,
                'sms' => true,
            ],
        ],
        'form_card' => [
            'enabled' => true,
            'title' => 'Reserve Your Free Seat',
            'body' => 'Enter your information below to register for this session.',
        ],
        'consent_header' => [
            'enabled' => true,
            'body' => 'Almost done — choose how you’d like to receive your details!',
        ],
        'sections' => [
            'notifications' => [
                'title' => 'Webinar Registration',
                'body' => 'At least one method is required.',
            ],
            'marketing' => [
                'title' => 'Stay Connected (Optional)',
            ],
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
                'label' => 'Mobile Phone',
                'placeholder' => 'Enter your mobile phone number',
                'helper' => 'Required to receive SMS.',
            ],
            'consent_messages' => [
                'email' => [
                    'label' => 'Email me my webinar confirmation, access link, reminders, replay, and webinar-related updates.',
                ],
                'sms' => [
                    'label' => 'Text me webinar reminders and access information.',
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
        'submit' => [
            'label' => 'Reserve My Free Seat',
        ],
        'legal_links' => [
            'enabled' => false,
            'intro' => 'By registering, you agree to our',
            'links' => [],
        ],
    ],

    'secondary_cta' => [
        'enabled' => true,
        'headline' => 'Get the information you need before taking the next step.',
        'label' => 'RESERVE YOUR FREE SEAT',
        'route' => 'webinar.show',
        'helper_text' => 'Registration takes only a moment.',
    ],

    'trust' => [
        'enabled' => false,
        'variant' => 'reviews',
        'headline' => null,
        'body' => null,
        'review_label' => null,
        'review_url' => null,
        'reviews' => [],
        'stories' => [],
    ],

    'final_close' => [
        'enabled' => true,
        'headline' => 'Make Your Next Move With a Clearer Plan',
        'bullets' => [
            'intro' => null,
            'list' => [],
        ],
        'body' => null,
        'closing_copy' => null,
        'label' => 'Reserve Your Free Seat',
        'helper_text' => 'Free live training',
        'countdown' => [
            'enabled' => false,
        ],
    ],

    'compliance' => [
        'enabled' => false,
        'text' => null,
    ],

    'sticky_desktop' => [
        'enabled' => true,
        'label' => 'RESERVE MY FREE SEAT',
        'eyebrow' => 'Next class starts in:',
    ],

    'sticky_mobile' => [
        'label' => 'Reserve My Free Seat',
    ],

    'blocks' => [
        'hero',
        'urgency_stats',
        'primary_cta',
        'event_details',
        'problem',
        'instructor',
        'form_card',
        'secondary_cta',
        'trust',
        'final_close',
        'compliance',
    ],

];