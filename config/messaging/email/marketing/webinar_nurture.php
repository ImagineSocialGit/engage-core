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
    | Default campaign copy may use universal Contact tokens. Do not include
    | runtime-only URL tokens unless the campaign enrollment/start payload
    | explicitly supplies them.
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
                        'body' => 'Hi {first_name}, after the webinar, many people have follow-up questions about what to do next. If you have a question, reply to this email and we’ll point you in the right direction.',
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
                        'body' => 'Hi {first_name}, sorry we missed you at the webinar. If the topic is still relevant, reply with your biggest question and we’ll help you with the next step.',
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Want to join the next class instead?',
                        'body' => 'Hi {first_name}, if the last webinar time did not work, reply to this email and we’ll help you find the next useful class or resource.',
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
