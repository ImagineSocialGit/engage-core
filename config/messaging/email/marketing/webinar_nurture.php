<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [
    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
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
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Thanks for joining — here are a few next steps',
                                'body' => 'Hi {first_name}, thanks for joining the webinar. If the topic is still on your mind, reply with your biggest question and we’ll help you with the next step.',
                            ],
                        ],
                    ],
                ],
                2 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Common questions after the webinar',
                                'body' => 'Hi {first_name}, after the webinar, many people have follow-up questions about what to do next. If you have a question, reply to this email and we’ll point you in the right direction.',
                            ],
                        ],
                    ],
                ],
                3 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Still interested?',
                                'body' => 'Hi {first_name}, thanks again for joining the webinar. This is the last message in this short follow-up sequence. Reply any time if you have a question or want help with the next step.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'webinar_missed_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Sorry we missed you',
                                'body' => 'Hi {first_name}, sorry we missed you at the webinar. If the topic is still relevant, reply with your biggest question and we’ll help with the next step.',
                            ],
                        ],
                    ],
                ],
                2 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Want to join the next class instead?',
                                'body' => 'Hi {first_name}, if the last webinar time did not work, reply to this email and we’ll help you find the next useful class or resource.',
                            ],
                        ],
                    ],
                ],
                3 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Still interested?',
                                'body' => 'Hi {first_name}, sorry we missed you at the webinar. This is the last message in this short follow-up sequence. Reply any time if you have a question or want help finding the next useful step.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
