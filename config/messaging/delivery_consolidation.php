<?php

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

return [
    'policies' => [
        'webinar_registration' => [
            /*
             * Core defaults to separate deliveries. A client may enable this
             * policy without changing consent persistence or provider behavior.
             */
            'enabled' => false,

            'groups' => [
                'initial_email' => [
                    'channel' => 'email',
                    'primary_intent' => 'webinar.registration.confirmation',
                    'member_intents' => [
                        'consent.transactional.email.acknowledgement',
                        'consent.marketing.email.acknowledgement',
                    ],
                    'include_marketing_unsubscribe' => true,

                    'template' => [
                        'key' => 'webinar_registration_initial',
                        'definition_key' => 'webinar_registration_initial',
                        'dispatch_keys' => ['registration_created'],
                        'message_type' => 'confirmation',
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'subject' => 'You’re registered for {webinar_title}',
                            'body' => <<<'TEXT'
Hi {first_name},

You’re registered for {webinar_title}.

Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

{delivery_consolidation_webinar_email_acknowledgement}

{delivery_consolidation_marketing_email_acknowledgement}

We look forward to seeing you there.
TEXT,
                            'cta' => [
                                'label' => 'Join Webinar',
                                'url' => '{webinar_join_url}',
                            ],
                            'secondary_link' => [
                                'label' => 'Need to cancel your registration?',
                                'url' => '{cancel_registration_url}',
                            ],
                        ],
                    ],

                    'fragments' => [
                        'delivery_consolidation_webinar_email_acknowledgement' => [
                            'intent_key' => 'consent.transactional.email.acknowledgement',
                            'text' => 'Webinar email updates are enabled for access details, reminders, replay information, and related follow-up.',
                        ],
                        'delivery_consolidation_marketing_email_acknowledgement' => [
                            'intent_key' => 'consent.marketing.email.acknowledgement',
                            'text' => 'You’re also subscribed to receive marketing emails from :client_name. You can unsubscribe at any time.',
                        ],
                    ],
                ],

                'initial_sms' => [
                    'channel' => 'sms',
                    'primary_intent' => 'webinar.registration.confirmation',
                    'member_intents' => [
                        'consent.transactional.sms.acknowledgement',
                    ],

                    'template' => [
                        'key' => 'webinar_registration_initial',
                        'definition_key' => 'webinar_registration_initial',
                        'dispatch_keys' => ['registration_created'],
                        'message_type' => 'confirmation',
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'payload_class' => SmsPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'message' => 'You’re registered for {webinar_title} on {webinar_start_date} at {webinar_start_time}. Join: {webinar_join_url} {delivery_consolidation_webinar_sms_acknowledgement}',
                        ],
                    ],

                    'fragments' => [
                        'delivery_consolidation_webinar_sms_acknowledgement' => [
                            'intent_key' => 'consent.transactional.sms.acknowledgement',
                            'text' => 'Webinar text updates are enabled. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to opt out.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
