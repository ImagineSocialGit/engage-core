<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing Webinar Nurture Email Templates
    |--------------------------------------------------------------------------
    |
    | Purpose/scope:
    |
    | marketing:webinar_nurture
    |
    | Generic webinar nurture templates.
    |
    | Campaign message templates are resolved by:
    |
    | campaigns.{campaign_key}.steps.{step_number}
    |
    */

    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'subject' => 'You’re subscribed',
                'body' => 'Thanks for subscribing to receive helpful follow-up messages. You can unsubscribe at any time.',
            ],
        ],
    ],

    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Thanks for joining — here are a few next steps',
                        'body' => 'Hi {first_name}, thanks for joining the webinar. If the topic is still on your mind, reply with your biggest question and we’ll help you with the next step.',
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Common questions after the webinar',
                        'body' => 'Hi {first_name}, after the webinar, many people have follow-up questions about what to do next. If you have a question, just reply and we’ll point you in the right direction.',
                        'cta' => [
                            'label' => 'Ask a Question',
                            'url' => '{contact_url}',
                        ],
                    ],
                ],

                3 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Still interested?',
                        'body' => 'Hi {first_name}, even if now is not the right time, we’ll keep sending helpful follow-up information so you can stay prepared.',
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Sorry we missed you',
                        'body' => 'Hi {first_name}, sorry we missed you at the webinar. If the topic is still relevant, you can reply with your biggest question or use the link below to continue.',
                        'cta' => [
                            'label' => 'Continue',
                            'url' => '{next_step_url}',
                        ],
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Want to join the next class instead?',
                        'body' => 'Hi {first_name}, if the last webinar time did not work, you can join an upcoming class instead.',
                        'cta' => [
                            'label' => 'See Upcoming Classes',
                            'url' => '{webinar_registration_url}',
                        ],
                    ],
                ],

                3 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Still interested?',
                        'body' => 'Hi {first_name}, even if now is not the right time, we’ll keep sending helpful follow-up information so you can stay prepared.',
                    ],
                ],
            ],
        ],
    ],

];