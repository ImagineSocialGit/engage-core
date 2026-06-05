<?php

// Registered content

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
        'eyebrow' => 'Live Homebuyer Seminar',
        'title_prefix' => null,
        'title' => 'Last Year, 80,000 Pre-Approved Buyers Were Denied After Going Under Contract',
        'body' => 'Most buyers think they’re approved. They’re not.',
        'supporting_copy' => [
            'By the time problems show up, it’s too late to fix them.',
        ],
        'class_details' => [
            'title' => 'In this class, I’ll show you how to get fully approved:',
            'bullets' => [
                'Income not structured correctly',
                'Assets not sourced properly',
                'Credit not fully reviewed',
                'Underwriting issues discovered too late',
            ],
        ],
        'bullets' => [
            'intro' => 'I give you the Roadmap: From Pre-Approval To Keys:',
            'list' => [
                'Get a full plan, strategy and pre-approval before you shop',
                'Know exactly what lenders actually verify',
                'Catch issues early—before you go under contract',
                'Avoid getting denied after you’ve made an offer',
            ],
        ],
        'image' => null,
    ],

    'urgency_stats' => [
        'enabled' => true,
        'intro' => 'Last year:',
        'items' => [
            [
                'value' => '400,000',
                'label' => 'contracts fell apart',
            ],
            [
                'value' => '280,000',
                'label' => 'buyers loans were denied',
            ],
            [
                'value' => '80,000',
                'label' => 'denials were after pre-approval, contract, and money spent',
            ],
        ],
    ],

    'webinar_title' => [
        'enabled' => true,
    ],

    'primary_cta' => [
        'enabled' => true,
        'pretext' => 'Next class starts in:',
        'label' => 'Lock In My Seat',
        'mobile_label' => 'Lock In My Seat',
        'desktop_header_label' => 'Reserve Spot',
        'route' => 'webinar.show',
        'helper_text' => 'Free training • No fluff • Real strategy',
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
            [
                ['text' => 'They lose it because'],
                ['text' => 'they didn’t have a plan—and a real pre-approval.', 'emphasis' => true],
            ],
            'A lot of pre-approvals are issued without fully reviewing everything upfront. Things get missed.',
            [
                ['text' => 'And buyers move forward with a'],
                ['text' => 'false sense of security.', 'emphasis' => true],
            ],
            [
                ['text' => 'By the time the problem shows up, you’re already under contract.'],
                ['text' => 'There’s no time to fix it.', 'emphasis' => true],
            ],
        ],
        'bullets' => [
            'intro' => 'That’s when everything falls apart.',
            'list' => [
                'Income not structured correctly',
                'Assets not sourced properly',
                'Credit not fully reviewed',
                'Underwriting issues discovered too late',
            ],
        ],
    ],

    'instructor' => [
        'enabled' => true,
        'image' => config('generated.images.instructor'),
        'image_alt' => 'Stacey Sandlin and family',
        'image_sizes' => '(min-width: 1024px) 34vw, 90vw',
        'image_caption' => 'A 3-generation mortgage family. We don’t guess — we underwrite upfront.',
        'eyebrow' => 'Why This Doesn’t Happen to My Clients',
        'heading' => 'I don’t guess.',
        'body' => [
            'I’ve spent 30 years inside this process — including underwriting loans before they ever get approved.',
            [
                ['text' => 'I know exactly where deals break.'],
                ['text' => 'I structure them so they don’t.', 'emphasis' => true],
            ],
            [
                ['text' => 'Most lenders don’t fully underwrite upfront.'],
            ],
            [
                ['text' => 'That’s where problems start.', 'emphasis' => true],
            ],
        ],
        'credibility' => [
            '30 years in lending',
            'Former underwriter',
            '$1 Billion+ funded',
            'Known for saving deals other lenders miss',
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
            'email' => [
                'label' => 'I agree to receive emails from Slam Dunk Home Loans related to webinar registration, including private access details, class reminders, replay access, and follow-up communications. I may unsubscribe at any time.',
            ],
            'sms' => [
                'label' => 'I agree to receive automated text messages from Slam Dunk Home Loans related to webinar registration, including private access details, class reminders, replay access, and follow-up communications. Consent is not a condition of registration. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help. Mobile information will not be shared with third parties for promotional or marketing purposes.',
            ]
        ],
    ],

    'submit' => [
        'label' => 'Reserve My Spot',
    ],

    'secondary_cta' => [
        'enabled' => true,
        'headline' => 'Don’t risk losing your deal because something was missed upfront.',
        'label' => 'SAVE MY SEAT BEFORE IT’S GONE',
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
        'bullets' => [
            'intro' => 'By the time something goes wrong',
            'list' => [
                'You’ve made an offer',
                'You’re under contract',
                'You’ve spent money',
                'You’re planning your move',
            ],
        ],
        'body' => 'And then everything stops.',
        'closing_copy' => 'Not because you couldn’t buy. Because it wasn’t set up right from the beginning.',
        'label' => 'Secure My Spot Before It Fills',
        'helper_text' => 'No pressure • No sales pitch • Just strategy',
        'countdown' => [
            'enabled' => false,
        ],
    ],

    'compliance' => [
        'enabled' => true,
        'text' => 'This is an educational class designed to help consumers better understand the homebuying and mortgage process. No application is required to attend. Loan approval is subject to credit, income, assets, and underwriting guidelines.',
    ],

    'sticky_desktop' => [
        'enabled' => true,
        'label' => 'LOCK IN MY SEAT NOW',
        'eyebrow' => 'Next class starts in:',
    ],

    'sticky_mobile' => [
        'label' => 'Save My Seat',
    ],

    'legal_links' => [
        'enabled' => true,
        'text' => 'By registering, you agree to our Terms & Conditions and Privacy Policy.',
        'links' => [
            [
                'label' => 'Terms & Conditions',
                'url' => 'https://slamdunkhomeloans.com/terms-and-conditions/',
            ],
            [
                'label' => 'Privacy Policy',
                'url' => 'https://slamdunkhomeloans.com/privacy-policy/',
            ],
        ],
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
