<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Email Messaging Template
    |--------------------------------------------------------------------------
    |
    | File path pattern:
    | config/messaging/email/{purpose}/{scope}.php
    | client/{client-key}/config/messaging/email/{purpose}/{scope}.php
    |
    | Create one file per purpose/scope pair.
    |
    | Examples:
    | config/messaging/email/transactional/webinar.php
    | config/messaging/email/transactional/permission_invitation.php
    | config/messaging/email/marketing/webinar_nurture.php
    | config/messaging/email/marketing/broadcast.php
    | config/messaging/email/marketing/mortgage_homebuyer_nurture.php
    | config/messaging/email/marketing/webinar_waitlist.php
    |
    | Non-campaign top-level keys are message types. They become
    | message_type at runtime.
    |
    | Campaign message templates live under:
    |
    | campaigns.{campaign_key}.steps.{step_number}
    |
    | Campaign templates do not own timing/schedule. Campaign presets own
    | campaign step timing and overlay timing before dispatching.
    |
    | Keep default webinar copy vertical-neutral.
    | Put vertical-specific copy in vertical-specific scopes.
    |
    | Normal Broadcasts usually provide ad hoc payloads inline from the
    | Broadcast record. Email Broadcast payloads use subject/body. Do not add
    | reusable Broadcast copy here unless a future workflow intentionally
    | dispatches Broadcast messages from Messaging config.
    */

    /*
    |--------------------------------------------------------------------------
    | transactional:webinar example shape
    |--------------------------------------------------------------------------
    */

    'confirmations' => [
        [
            'dispatch_key' => 'registration_created',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar.starts_at',
                    'operator' => 'at_least_minutes_from_now',
                    'value' => 30,
                ],
            ],

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 15,
            ],

            'payload' => [
                'subject' => 'You’re registered for {webinar_title}',
                'body' => <<<'TEXT'
Hi {first_name},

You’re registered for {webinar_title}.

Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

We’ll send reminders as the event gets closer.
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
                'secondary_link' => [
                    'label' => 'Need to cancel?',
                    'url' => '{cancel_registration_url}',
                ],
            ],
        ],
    ],

    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'subject' => 'You’re subscribed to webinar emails',
                'body' => 'Thanks for subscribing to receive webinar-related emails. You can opt out using the link in any webinar email.',
            ],
        ],
    ],

    'reminders' => [
        [
            'dispatch_key' => 'registration_created',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -1440,
            ],

            'payload' => [
                'subject' => 'Your webinar is tomorrow',
                'body' => 'Hi {first_name}, your webinar is tomorrow. Use the link below when it is time to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'dispatch_key' => 'registration_created',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'skip_when_join_clicked' => true,
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => 5,
            ],

            'payload' => [
                'subject' => 'Your webinar is live',
                'body' => 'Hi {first_name}, {webinar_title} is live. Use the link below to join now.',
                'cta' => [
                    'label' => 'Join Now',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
    ],

    'post_attended' => [
        [
            'dispatch_key' => 'webinar_ended',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'filled',
                ],
            ],

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Thanks for joining {webinar_title}',
                'body' => 'Hi {first_name}, thanks for joining {webinar_title}. You can watch the replay using the link below.',
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],

    'post_missed' => [
        [
            'dispatch_key' => 'webinar_ended',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'blank',
                ],
            ],

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Sorry we missed you — here’s the replay',
                'body' => 'Hi {first_name}, sorry we missed you at {webinar_title}. You can still watch the replay using the link below.',
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | marketing:broadcast note
    |--------------------------------------------------------------------------
    |
    | Regular email Broadcasts are single-channel ad hoc sends. The Broadcast
    | stores payload.subject and payload.body, then passes an inline Messaging
    | definition with dispatch_key = broadcast_send. That means this template
    | normally does not need to define reusable broadcast copy.
    |
    | If a future feature intentionally dispatches reusable Broadcast-style
    | email from config, it should use purpose = marketing, scope = broadcast,
    | dispatch_key = broadcast_send, and EmailPayload::class.
    */

    /*
    |--------------------------------------------------------------------------
    | marketing:webinar_nurture campaign template example
    |--------------------------------------------------------------------------
    |
    | In a real config file, keep only the sections that belong to that
    | purpose/scope. Do not mix transactional and marketing definitions in one
    | deployed config file unless that file truly represents that purpose/scope.
    |
    | Campaign templates may always use universal Contact tokens when the
    | recipient is a Contact:
    |
    | {first_name}
    | {last_name}
    | {name}
    | {email}
    | {phone}
    |
    | Do not include runtime-only URL tokens such as {next_step_url},
    | {application_url}, {contact_url}, or {webinar_registration_url} unless
    | the campaign enrollment/start path explicitly supplies them.
    */

    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Thanks for joining — here are a few next steps',
                        'body' => 'Hi {first_name}, thanks for joining the webinar. Reply with your biggest question and we’ll help you with the next step.',
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
                        'body' => 'Hi {first_name}, sorry we missed you at the webinar. Reply with your biggest question and we’ll help you with the next step.',
                    ],
                ],
            ],
        ],
    ],

];
