<?php

return [
    'policies' => [
        'webinar_registration' => [
            /*
             * Core defaults to separate deliveries. A client may enable this
             * policy without changing consent persistence or provider behavior.
             *
             * Composition references immutable acknowledgement template versions
             * through scheduled_message_components. It never copies component
             * text or a consolidation recipe into ScheduledMessage payload/meta.
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
                    'placement_key' => 'email_body_append',
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
                    'placement_key' => 'sms_message_append',
                ],
            ],
        ],
    ],
];