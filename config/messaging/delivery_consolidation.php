<?php

return [
    'policies' => [
        'webinar_registration' => [
            /*
             * Core defaults to separate deliveries. A client may enable this
             * policy without changing consent persistence or provider behavior.
             *
             * Consolidation decorates the selected primary message definition.
             * It must never replace client-authored or CRM-selected templates.
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

                    'placement' => [
                        'payload_key' => 'body',
                        'position' => 'append',
                        'separator' => "\n\n",
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

                    'placement' => [
                        'payload_key' => 'message',
                        'position' => 'append',
                        'separator' => "\n\n",
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
