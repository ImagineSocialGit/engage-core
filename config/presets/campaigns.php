<?php

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets
    |--------------------------------------------------------------------------
    |
    | Campaign preset definitions live here instead of in config/presets.php so
    | the high-level client preset file stays readable.
    |
    | Campaign = journey.
    | CampaignStep = one independently skippable touch.
    |
    | Email should carry the core narrative. SMS should only supplement the
    | journey and must be safe to skip when consent is missing.
    |
    */

    'groups' => [
        'webinar_default' => [
            'webinar_attended_nurture',
            'webinar_missed_nurture',
            'long_term_homebuyer_nurture',
        ],

        'mortgage_default' => [
            'webinar_attended_nurture',
            'webinar_missed_nurture',
            'long_term_homebuyer_nurture',
        ],
    ],

    'definitions' => [

        'webinar_attended_nurture' => [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Default follow-up sequence for contacts who attended a webinar but have not yet taken the next step.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Email carries the core narrative. SMS steps are optional nudges and may be skipped without breaking the campaign.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended thank-you and next step',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Thanks for joining — here’s your next step',
                        'body' => 'Hi {first_name}, thanks for joining the webinar. If buying a home is still on your radar, the best next step is to get clear on your numbers and timeline. When you’re ready, start here.',
                        'cta' => [
                            'label' => 'Start Your Next Step',
                            'url' => '{application_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'attended_thank_you_next_step',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Attended SMS check-in',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'payload' => [
                        'to' => '{phone}',
                        'message' => 'Hi {first_name}, thanks again for joining the webinar. Quick question: are you looking to buy soon, or just getting prepared?',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'attended_sms_check_in',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Common next-step questions',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'The questions most buyers ask after the webinar',
                        'body' => 'Hi {first_name}, after the webinar, most buyers want to know what they can afford, how much cash they need, and what to do first. The easiest way to answer those is to review your situation directly.',
                        'cta' => [
                            'label' => 'Request a Review',
                            'url' => '{application_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'attended_common_questions',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Long-term nurture handoff',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Still planning your move?',
                        'body' => 'Hi {first_name}, even if you are not ready right now, staying prepared can make the process much easier later. I’ll keep sending helpful buyer tips from time to time.',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'attended_long_term_handoff',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'key' => 'webinar_missed_nurture',
            'name' => 'Webinar Missed Nurture',
            'description' => 'Default replay and re-engagement sequence for contacts who registered but missed the webinar.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Email carries replay/value. SMS is a supplemental nudge only.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar replay',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 1,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Sorry we missed you — here’s the replay',
                        'body' => 'Hi {first_name}, sorry we missed you at the webinar. You can still catch the important parts here.',
                        'cta' => [
                            'label' => 'Watch the Replay',
                            'url' => '{webinar_replay_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'missed_replay',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Missed SMS replay nudge',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'payload' => [
                        'to' => '{phone}',
                        'message' => 'Hi {first_name}, sorry we missed you at the webinar. I sent the replay by email in case you still want to watch it.',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'missed_sms_replay_nudge',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Missed webinar next class invite',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Want to join the next class instead?',
                        'body' => 'Hi {first_name}, if the last webinar time did not work, you can join an upcoming class instead.',
                        'cta' => [
                            'label' => 'See Upcoming Classes',
                            'url' => '{webinar_registration_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'missed_next_class_invite',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Missed webinar long-term handoff',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Still planning ahead?',
                        'body' => 'Hi {first_name}, even if now is not the right time, I’ll keep sending helpful buyer tips so you can stay prepared.',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'message_type' => 'missed_long_term_handoff',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
            ],
        ],

        'long_term_homebuyer_nurture' => [
            'key' => 'long_term_homebuyer_nurture',
            'name' => 'Long-Term Homebuyer Nurture',
            'description' => 'Default long-term homebuyer education sequence for contacts who are not ready immediately.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'homebuyer_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Default long-term nurture. This is intentionally lighter than a full client-specific drip.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Preparation basics',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 14,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'The best time to prepare is before you are ready',
                        'body' => 'Hi {first_name}, if buying a home is still a future goal, a little preparation now can make the process much easier later. Start with your budget, credit, cash needed, and timeline.',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'homebuyer_nurture',
                            'message_type' => 'homebuyer_preparation_basics',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Check-in nudge',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 30,
                        ],
                    ],
                    'payload' => [
                        'to' => '{phone}',
                        'message' => 'Hi {first_name}, quick check-in — are you still thinking about buying a home later this year, or is it more of a future goal?',
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'homebuyer_nurture',
                            'message_type' => 'homebuyer_sms_check_in',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Timeline education',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 45,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'What to do 3–6 months before buying',
                        'body' => 'Hi {first_name}, if you are 3–6 months away from buying, this is a good time to review your credit, avoid major debt changes, estimate cash needed, and talk through your loan options.',
                        'cta' => [
                            'label' => 'Ask a Question',
                            'url' => '{contact_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'homebuyer_nurture',
                            'message_type' => 'homebuyer_timeline_education',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Soft reactivation',
                    'dispatch_key' => 'marketing_message_sent',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 90,
                        ],
                    ],
                    'payload' => [
                        'to' => '{email}',
                        'subject' => 'Should we revisit your homebuying plan?',
                        'body' => 'Hi {first_name}, checking in to see whether buying a home is still on your radar. If it is, we can revisit your numbers and next steps.',
                        'cta' => [
                            'label' => 'Revisit My Plan',
                            'url' => '{application_url}',
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'homebuyer_nurture',
                            'message_type' => 'homebuyer_soft_reactivation',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                        ],
                    ],
                ],
            ],
        ],

    ],

];