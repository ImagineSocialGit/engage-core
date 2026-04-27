<?php

return [

    'title' => 'Register for Webinar',
    'meta_description' => 'Reserve your spot for this live online mortgage strategy class.',

    'header' => [
        'primary_link' => [
            'label' => 'Reserve Spot',
            'route' => 'webinar.index',
        ],
    ],

    'series_overrides' => [
        'eyebrow' => true,
        'hero_title' => true,
        'hero_body' => true,
        'urgency_stats' => true,
        'event_details' => true,
        'primary_cta' => true,
        'instructor' => true,
        'secondary_cta' => true,
        'trust' => true,
        'hero_image' => true,
    ],

    'hero' => [
        'enabled' => true,
        'eyebrow' => 'Live Online Class',
        'title_prefix' => null,
        'title' => '80,000 Buyers Were Denied After Going Under Contract Last Year',
        'subtitle' => 'Even After Being Pre-Approved',
        'body' => 'Most buyers think they’re approved… until their deal falls apart.',
        'supporting_copy' => 'If your loan isn’t structured correctly upfront, you don’t find out early. You find out when the deal is already on the line.',
        'closing_copy' => 'I’ll show you how to avoid getting denied and how to protect your deal once you’re under contract.',
        'authority_line' => 'Taught by a 30-year mortgage strategist, former underwriter, $1B+ funded.',
        'image' => null,
    ],

    'urgency_stats' => [
        'enabled' => true,
        'intro' => 'Last year:',
        'items' => [
            [
                'value' => '400,000',
                'label' => 'deals fell apart',
            ],
            [
                'value' => '280,000',
                'label' => 'buyers were denied',
            ],
            [
                'value' => '80,000',
                'label' => 'denials happened after going under contract',
            ],
        ],
        'closing_line' => 'Here’s how to make sure that does not happen to you.',
    ],

    'primary_cta' => [
        'enabled' => true,
        'pretext' => 'Next class starts in:',
        'label' => 'Save My Seat',
        'mobile_label' => 'Save My Seat',
        'desktop_header_label' => 'Reserve Spot',
        'route' => 'webinar.show',
        'helper_text' => 'Free training • No fluff • Real strategy',
    ],

    'countdown' => [
        'enabled' => true,
        'label' => 'Next class starts in:',
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
                'value' => 'Live on Zoom',
                'icon' => 'map_pin',
            ],
        ],
    ],

    'problem' => [
        'enabled' => true,
        'eyebrow' => 'Where Deals Actually Fall Apart',
        'heading' => 'Pre-approval is not the finish line. It’s the starting point.',
        'body' => [
            'Most buyers don’t lose their deal because they couldn’t qualify.',
            'They lose it because something was missed early.',
            'By the time it shows up, you’re already under contract. That’s when deals fall apart.',
        ],
        'bullets' => [
            'Income not structured correctly',
            'Assets not sourced properly',
            'Credit not fully reviewed',
            'Underwriting issues discovered too late',
        ],
    ],

    'instructor' => [
        'enabled' => true,
        'image' => config('generated.images.webinar-landing'),
        'image_alt' => 'Stacey Sandlin and family',
        'image_sizes' => '(min-width: 1024px) 34vw, 90vw',
        'image_caption' => 'A 3-generation mortgage family. We don’t guess — we underwrite upfront.',
        'eyebrow' => 'Why This Doesn’t Happen to My Clients',
        'heading' => 'I don’t guess.',
        'body' => [
            'I’ve spent 30 years inside this process — including underwriting loans before they ever get approved.',
            'I’ve seen exactly where deals break, and I structure them so they don’t.',
            'Most lenders don’t fully underwrite upfront. That’s where problems start.',
        ],
        'credibility' => [
            '30 years in lending',
            'Former underwriter',
            '$1 Billion+ funded',
            'Known for saving deals other lenders kill',
        ],
    ],

    'form_card' => [
        'enabled' => true,
        'title' => 'Save Your Seat',
        'body' => 'Enter your information below to register for this session.',
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
        'sms_reminder' => [
            'label' => 'Text me the reminder',
        ],
        'consent_messages' => [
            'label' => 'I agree to receive automated email and text messages related to webinar registration, including private access details, class reminders, replay access, and follow-up communications. Message frequency varies. Reply STOP to opt out.',
        ],
    ],

    'submit' => [
        'label' => 'Reserve My Spot',
    ],

    'secondary_cta' => [
        'enabled' => true,
        'headline' => 'Don’t risk losing your deal because something was missed upfront.',
        'label' => 'Save My Seat',
        'route' => 'webinar.show',
        'helper_text' => 'Takes 10 seconds to register.',
    ],

    'trust' => [
        'enabled' => true,
        'headline' => '5.0 Rating — Trusted by Buyers Who Actually Closed',
        'body' => 'Real buyers. Real deals. Real problems caught before they became disasters.',
        'review_label' => 'View Reviews',
        'review_url' => null,
        'reviews' => [
            [
                'name' => 'Daniel Kerbs',
                'stars' => '★★★★★',
                'text' => 'Stacey and her team were incredibly helpful and went above and beyond to help me get the refinance loan I needed to get moving on the next chapter of my life.',
            ],
            [
                'name' => 'Shawna Newberry',
                'stars' => '★★★★★',
                'text' => 'From pre-approval to closing, Stacey keeps everything moving seamlessly.',
            ],
            [
                'name' => 'Luca Pretolesi',
                'stars' => '★★★★★',
                'text' => 'Stacy was absolutely phenomenal. Her speed, precision, and attention to detail were truly impressive, and she made the entire lending process smooth, clear, and stress-free.',
            ],
        ],
    ],

    'final_close' => [
        'enabled' => true,
        'headline' => 'Most Buyers Don’t Know There’s a Problem… Until It’s Too Late',
        'body' => 'By the time something goes wrong, you’ve already made an offer, spent money, and started planning your move.',
        'bullets' => [
            'You’ve made an offer',
            'You’re under contract',
            'You’ve spent money',
            'You’re planning your move',
        ],
        'closing_copy' => 'Not because you couldn’t buy. Because it wasn’t set up right from the beginning.',
        'label' => 'Secure My Spot Before It Fills',
        'helper_text' => 'No pressure • No sales pitch • Just strategy',
        'countdown' => [
            'enabled' => false,
        ]
    ],

    'exit_intent' => [
        'enabled' => true,
        'headline' => 'Wait — before you go… this could save your deal',
        'label' => 'Let Me In',
    ],

    'compliance' => [
        'enabled' => true,
        'text' => 'This is an educational class designed to help consumers better understand the homebuying and mortgage process. No application is required to attend. Loan approval is subject to credit, income, assets, and underwriting guidelines.',
    ],

    'sticky_desktop' => [
        'enabled' => true,
        'label' => 'Save My Seat',
        'eyebrow' => 'Next class starts in:',
    ],

    'sticky_mobile' => [
        'label' => 'Save My Seat',
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