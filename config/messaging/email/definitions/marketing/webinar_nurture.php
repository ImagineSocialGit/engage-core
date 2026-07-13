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
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'subject' => 'You’re subscribed to webinar follow-up emails',
                'body' => 'Thanks for subscribing to webinar follow-up emails. You can unsubscribe at any time.',
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
                                'subject' => 'Thanks again for joining us',
                                'body' => <<<'TEXT'
Hi {first_name},

Thanks again for joining the webinar.

I hope the session gave you something useful to take away. If you still have questions or want help figuring out your next step, just reply to this email.
TEXT,
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
                                'body' => <<<'TEXT'
Hi {first_name},

Sorry we missed you at the webinar.

If you’re still interested, you can reply with any questions or let us know what would be most useful to you next.
TEXT,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
