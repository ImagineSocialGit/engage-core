<?php

return [
    'policies' => [
        'webinar_registration' => [
            /*
             * Core defaults to separate deliveries. A client may enable this
             * policy without changing consent persistence or provider behavior.
             *
             * Consolidation decorates the first eligible lifecycle message:
             * confirmation first, then the earliest future reminder. When no
             * lifecycle delivery remains, same-channel acknowledgements may use
             * one standalone acknowledgement template as their shared carrier.
             */
            'enabled' => false,

            'groups' => [
                'initial_email' => [
                    'channel' => 'email',
                    'primary_intent' => 'webinar.registration.confirmation',
                    'fallback_message_types' => [
                        'reminder',
                    ],
                    'member_intents' => [
                        'consent.transactional.email.acknowledgement',
                        'consent.marketing.email.acknowledgement',
                    ],
                    'standalone_primary_intents' => [
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
                    'fallback_message_types' => [
                        'reminder',
                    ],
                    'member_intents' => [
                        'consent.transactional.sms.acknowledgement',
                        'consent.marketing.sms.acknowledgement',
                    ],
                    'standalone_primary_intents' => [
                        'consent.transactional.sms.acknowledgement',
                        'consent.marketing.sms.acknowledgement',
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
                        'delivery_consolidation_marketing_sms_acknowledgement' => [
                            'intent_key' => 'consent.marketing.sms.acknowledgement',
                            'text' => 'Marketing text updates from :client_name are also enabled.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];