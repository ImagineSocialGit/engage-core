<?php

return [

    'brand' => [
        'name' => config('app.name'),
        'logo' => null,
        'logo_alt' => config('app.name'),
    ],

    'layout' => [
        'body' => 'min-h-screen flex flex-col bg-secondary text-white',
        'main' => 'flex-1',
        'container' => 'mx-auto w-full max-w-7xl px-6',
        'content_width' => 'mx-auto w-full max-w-4xl',
        'card' => 'rounded-3xl border border-white/10 bg-white shadow-2xl shadow-black/20',
        'card_padding' => 'p-8 sm:p-10',
        'header' => [
            'wrap' => 'border-b border-white/10 bg-secondary/95 backdrop-blur',
            'inner' => 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4',
            'brand' => 'text-lg font-semibold tracking-tight text-white',
            'nav' => 'hidden items-center gap-6 text-sm font-medium text-white/70 md:flex',
            'nav_link' => 'transition hover:text-primary',
            'primary_link' => [
                'label' => 'Webinars',
                'route' => 'webinar.index',
            ],
        ],
        'footer' => [
            'wrap' => 'border-t border-white/10 bg-secondary',
            'inner' => 'mx-auto w-full max-w-7xl px-6 py-8 text-sm text-white/60',
            'text' => config('app.name'),
        ],
    ],

    'components' => [
        'button' => [
            'base' => 'inline-flex items-center justify-center rounded-2xl font-semibold tracking-tight transition duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-secondary disabled:pointer-events-none disabled:opacity-60',
            'sizes' => [
                'sm' => 'min-h-10 px-4 py-2 text-sm',
                'md' => 'min-h-12 px-5 py-3 text-sm',
                'lg' => 'min-h-14 px-7 py-3.5 text-base',
                'xl' => 'min-h-16 px-8 py-4 text-lg',
            ],
            'variants' => [
                'primary' => 'bg-primary text-white shadow-lg shadow-black/20 hover:brightness-110 focus:ring-primary',
                'secondary' => 'border border-white/15 bg-white text-secondary hover:bg-white/90 focus:ring-white',
                'outline' => 'border border-primary bg-transparent text-primary hover:bg-primary/10 focus:ring-primary',
                'ghost' => 'bg-transparent text-white hover:bg-white/10 focus:ring-white/40',
            ],
        ],
        'card' => [
            'base' => 'rounded-3xl border border-white/10 bg-white text-slate-900 shadow-2xl shadow-black/20',
            'padding' => [
                'none' => '',
                'sm' => 'p-4 sm:p-5',
                'md' => 'p-6 sm:p-8',
                'lg' => 'p-8 sm:p-10',
            ],
        ],
        'checkbox' => [
            'wrapper' => 'flex items-start gap-3',
            'input' => 'mt-1 h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary',
            'label' => 'text-sm leading-6 text-slate-700',
        ],
        'input' => [
            'base' => 'block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/20',
        ],
    ],

    'tokens' => [
        'eyebrow' => 'text-sm font-semibold uppercase tracking-[0.24em] text-primary',
        'hero_title' => 'text-4xl font-bold tracking-tight text-white sm:text-5xl',
        'section_title' => 'text-3xl font-bold tracking-tight text-primary sm:text-4xl',
        'body' => 'text-base leading-7 text-white/80 sm:text-lg',
        'muted' => 'text-sm text-white/60',
        'list_link' => 'text-base font-semibold text-primary underline decoration-primary underline-offset-4 transition hover:text-primary hover:decoration-primary',
        'primary_button' => 'w-full',
        'secondary_button' => '',
        'form_grid' => 'space-y-4',
        'field_error' => 'mt-1 text-sm text-red-600',
    ],

    'pages' => [

        'index' => [
            'title' => 'Upcoming Webinars',
            'meta_description' => 'Browse upcoming webinar opportunities and reserve your spot.',
            'section' => 'mx-auto max-w-4xl px-6 py-16 sm:py-24',
            'hero' => [
                'enabled' => true,
                'align' => 'text-center',
                'eyebrow' => 'Live Online Classes',
                'title' => 'Browse upcoming webinars',
                'body' => 'Choose a webinar series below to view details and register for the next available session.',
                'wrapper' => 'mx-auto max-w-4xl',
            ],
            'series_list' => [
                'enabled' => true,
                'heading' => 'Available webinar series',
                'wrapper' => 'mt-12 rounded-3xl border border-white/10 bg-white p-8 shadow-2xl shadow-black/20',
                'list' => 'mt-6 space-y-4',
                'empty_hide' => true,
            ],
            'blocks' => [
                'hero',
                'series_list',
            ],
        ],

        'register' => [
            'title' => 'Register for Webinar',
            'meta_description' => 'Reserve your spot for an upcoming webinar.',
            'section' => 'mx-auto w-full max-w-6xl px-6 py-16 sm:py-24',
            'grid' => 'mt-16 grid gap-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-start',

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
                'align' => 'text-center',
                'eyebrow' => 'Live Online Class',
                'title_prefix' => 'Reserve your spot for',
                'title' => null,
                'body' => 'Learn how to avoid costly financing mistakes, understand what lenders actually review, and move forward with more confidence.',
                'supporting_copy' => 'Clear guidance. Practical strategy. Real-world mortgage insight.',
                'image' => null,
                'wrapper' => 'mx-auto max-w-4xl',
            ],

            'urgency_stats' => [
                'enabled' => true,
                'wrapper' => 'mt-6 space-y-1 text-center',
                'items' => [
                    [
                        'value' => '400,000',
                        'label' => 'deals fell apart last year',
                    ],
                    [
                        'value' => '280,000',
                        'label' => 'loans were denied',
                    ],
                    [
                        'value' => '80,000',
                        'label' => 'buyers lost their home after going under contract',
                    ],
                ],
                'closing_line' => "Here’s how to make sure that doesn't happen to you.",
            ],

            'primary_cta' => [
                'enabled' => true,
                'wrapper' => 'mt-10 flex flex-col items-center gap-4 text-center',
                'pretext' => 'Seats are limited for this live training.',
                'label' => 'Save My Seat',
                'route' => 'webinar.show',
                'helper_text' => 'No pressure. No fluff. Just strategy.',
            ],

            'event_details' => [
                'enabled' => true,
                'heading' => 'Event Details',
                'wrapper' => 'mt-16',
                'heading_class' => 'text-center',
                'grid' => 'mt-8 grid gap-4 md:grid-cols-3',
                'card' => 'rounded-2xl border border-white/10 bg-white p-6 shadow-2xl shadow-black/20',
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
                'footnote' => "Once you register, we'll send your private access details and reminders so you don't miss it.",
            ],

            'instructor' => [
                'enabled' => true,
                'wrapper' => 'mt-20 grid gap-10 lg:grid-cols-[0.95fr_1.05fr] lg:items-center',
                'image' => null,
                'image_alt' => 'Instructor',
                'image_wrapper' => 'mx-auto max-w-md',
                'eyebrow' => null,
                'heading' => 'Meet Your Mortgage Strategist',
                'body' => [
                    "You’ll learn from a mortgage strategist with real underwriting perspective and years of hands-on industry experience.",
                    "This class is built to help buyers understand what lenders actually review, where approvals break down, and how to protect a deal before problems show up late in the process.",
                ],
            ],

            'form_card' => [
                'enabled' => true,
                'title' => 'Save Your Seat',
                'body' => 'Enter your information below to register for this session.',
                'class' => '',
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
                    'label' => 'I agree to receive automated email and text messages related to webinar registration, reminders, replay access, and follow-up communications. Message frequency varies. Reply STOP to opt out.',
                ],
            ],

            'submit' => [
                'label' => 'Reserve My Spot',
            ],

            'secondary_cta' => [
                'enabled' => true,
                'wrapper' => 'mt-20 text-center',
                'headline' => "Don’t risk losing your deal because of bad information",
                'label' => 'Reserve Your Spot Now',
                'route' => 'webinar.show',
                'helper_text' => 'Limited seats. Live training. Clear next steps.',
            ],

            'trust' => [
                'enabled' => true,
                'wrapper' => 'mt-16 text-center',
                'headline' => 'Trusted by homebuyers nationwide',
                'body' => 'Built to help real buyers make stronger decisions before they go under contract.',
                'review_label' => 'View Reviews',
                'review_url' => null,
            ],

            'compliance' => [
                'enabled' => true,
                'wrapper' => 'mt-16 text-center',
                'text' => 'This is an educational class designed to help consumers better understand the homebuying and mortgage process. No application is required to attend. Loan approval is subject to credit, income, assets, and underwriting guidelines.',
            ],

            'blocks' => [
                'hero',
                'urgency_stats',
                'primary_cta',
                'event_details',
                'instructor',
                'form_card',
                'secondary_cta',
                'trust',
                'compliance',
            ],
        ],

        'thank_you' => [
            'title' => 'Registration Complete',
            'meta_description' => 'Your webinar registration has been received.',
            'section' => 'mx-auto w-full max-w-3xl px-6 py-16 sm:py-24',
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
        ],

        'none_scheduled' => [
            'title' => 'No Upcoming Session',
            'meta_description' => 'This webinar series does not currently have an upcoming scheduled session.',
            'section' => 'mx-auto max-w-4xl px-6 py-16 sm:py-24',
            'hero' => [
                'enabled' => true,
                'align' => 'text-center',
                'title_prefix' => 'There is not an upcoming session currently scheduled for',
                'body' => 'That series is still available, but the next date has not been posted yet. Check the other available webinars below.',
            ],
            'series_list' => [
                'enabled' => true,
                'heading' => 'Other available webinars',
                'wrapper' => 'mt-12 rounded-3xl border border-white/10 bg-white p-8 shadow-2xl shadow-black/20',
                'list' => 'mt-6 space-y-4',
                'empty_hide' => true,
            ],
            'blocks' => [
                'hero',
                'series_list',
            ],
        ],

    ],

];