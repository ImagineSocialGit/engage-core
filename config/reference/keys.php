<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engage Core Config Key Registry
    |--------------------------------------------------------------------------
    |
    | This file documents stable keys used when creating default configs,
    | client configs, preset files, message definitions, campaign definitions,
    | FlowRoute definitions, and post-event behavior.
    |
    | Use this registry before adding a new key:
    | 1. If an existing key matches the behavior, use it.
    | 2. If behavior is meaningfully distinct, add a new key here.
    | 3. Client-owned keys may live in client/{client-key}/config/reference/keys.php.
    |
    | Keys are identifiers for behavior/configuration. They are not message tokens.
    */

    'channels' => [
        'email' => [
            'description' => 'Email delivery through Messaging.',
            'status' => 'active',
        ],
        'sms' => [
            'description' => 'SMS delivery through Messaging.',
            'status' => 'active',
        ],
    ],

    'purposes' => [
        'transactional' => [
            'description' => 'Required or expected lifecycle communication tied to a user/requested action.',
            'examples' => [
                'webinar confirmation',
                'webinar reminders',
                'webinar replay follow-up',
            ],
            'status' => 'active',
        ],
        'marketing' => [
            'description' => 'Promotional, nurture, conversion, re-engagement, or educational messaging.',
            'examples' => [
                'webinar nurture',
                'long-term homebuyer nurture',
                'waitlist availability updates',
            ],
            'status' => 'active',
        ],
        'internal' => [
            'description' => 'Team-facing notification messages.',
            'examples' => [
                'inbound reply notification',
                'task digest',
            ],
            'status' => 'active',
        ],
    ],

    'scopes' => [
        'webinar' => [
            'description' => 'Webinar-owned transactional messaging such as confirmations, reminders, live reminders, cancellations, and replay follow-ups.',
            'preferred_purposes' => ['transactional'],
            'status' => 'active',
        ],
        'webinar_nurture' => [
            'description' => 'Marketing nurture after webinar behavior such as attended/missed campaign journeys.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'webinar_waitlist' => [
            'description' => 'Marketing/waitlist messages for leads waiting for future webinar availability.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'inbound_messages' => [
            'description' => 'Internal notifications about inbound messages/replies.',
            'preferred_purposes' => ['internal'],
            'status' => 'active',
        ],
        'tasks' => [
            'description' => 'Internal task notification and digest behavior.',
            'preferred_purposes' => ['internal'],
            'status' => 'planned',
        ],
    ],

    'queues' => [
        'confirmation_messages' => [
            'description' => 'Registration confirmations and similar confirmation messages.',
            'status' => 'active',
        ],
        'opt_in_messages' => [
            'description' => 'Consent opt-in confirmation messages.',
            'status' => 'active',
        ],
        'reminders' => [
            'description' => 'Scheduled reminder messages.',
            'status' => 'active',
        ],
        'post_event' => [
            'description' => 'Post-webinar/provider event processing and follow-up messages.',
            'status' => 'active',
        ],
        'marketing' => [
            'description' => 'Marketing campaign/nurture message scheduling.',
            'status' => 'active',
        ],
        'waitlist' => [
            'description' => 'Waitlist-related notices.',
            'status' => 'active',
        ],
        'notifications' => [
            'description' => 'Internal/team notifications.',
            'status' => 'active',
        ],
    ],

    'dispatch_keys' => [
        'registration_created' => [
            'description' => 'A webinar registration was created and registration-related messages should be planned.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'webinar registration confirmation',
                'webinar reminders',
                'live webinar join reminder',
            ],
            'typical_channel_purpose_scope' => ['email:transactional:webinar', 'sms:transactional:webinar'],
            'status' => 'active',
        ],
        'consent_granted' => [
            'description' => 'Message consent was newly granted.',
            'used_by' => ['messaging'],
            'recommended_for' => [
                'opt-in confirmation messages',
            ],
            'status' => 'active',
        ],
        'webinar_added' => [
            'description' => 'A new webinar became available for waitlisted leads.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'webinar waitlist availability notification',
            ],
            'typical_channel_purpose_scope' => ['email:marketing:webinar_waitlist', 'sms:marketing:webinar_waitlist'],
            'status' => 'active',
        ],
        'webinar_ended' => [
            'description' => 'A webinar ended or recording became available and transactional post-webinar follow-up should be planned.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'attended replay follow-up',
                'missed replay follow-up',
            ],
            'typical_channel_purpose_scope' => ['email:transactional:webinar', 'sms:transactional:webinar'],
            'status' => 'active',
        ],
        'campaign_step_due' => [
            'description' => 'A campaign step is due and should schedule/send its message template.',
            'used_by' => ['campaigns', 'messaging'],
            'recommended_for' => [
                'campaign nurture messages',
                'long-term drip messages',
            ],
            'typical_channel_purpose_scope' => ['email:marketing:webinar_nurture', 'sms:marketing:webinar_nurture'],
            'status' => 'active',
        ],
        'marketing_message_sent' => [
            'description' => 'Legacy/alternate campaign step trigger. Prefer campaign_step_due for new configs.',
            'used_by' => ['campaigns'],
            'recommended_for' => [],
            'status' => 'legacy',
        ],
    ],

    'message_types' => [
        'registration_confirmation' => [
            'description' => 'Initial webinar registration confirmation.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_reminder_10d' => [
            'description' => 'Ten-day webinar reminder.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_reminder_7d' => [
            'description' => 'Seven-day webinar reminder.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_reminder_24h' => [
            'description' => 'Twenty-four-hour webinar reminder.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_reminder_30m' => [
            'description' => 'Thirty-minute webinar reminder.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_reminder_10m' => [
            'description' => 'Ten-minute webinar reminder.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'webinar_live_now' => [
            'description' => 'Live-now webinar join message.',
            'dispatch_key' => 'registration_created',
            'status' => 'recommended',
        ],
        'post_attended' => [
            'description' => 'Post-webinar message for attended registrations.',
            'dispatch_key' => 'webinar_ended',
            'status' => 'recommended',
        ],
        'post_missed' => [
            'description' => 'Post-webinar message for missed registrations.',
            'dispatch_key' => 'webinar_ended',
            'status' => 'recommended',
        ],
        'marketing_opt_in' => [
            'description' => 'Marketing opt-in confirmation.',
            'dispatch_key' => 'consent_granted',
            'status' => 'recommended',
        ],
        'waitlist_webinar_added' => [
            'description' => 'Notify a waitlisted lead that a webinar is available.',
            'dispatch_key' => 'webinar_added',
            'status' => 'recommended',
        ],
        'attended_thank_you_next_step' => [
            'description' => 'First attended nurture email.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
        'attended_common_questions' => [
            'description' => 'Attended nurture email covering common next-step questions.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
        'attended_long_term_handoff' => [
            'description' => 'Attended nurture message handing off to long-term nurture.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
        'missed_replay_next_step' => [
            'description' => 'First missed nurture/replay email.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
        'missed_join_next_webinar' => [
            'description' => 'Invite missed webinar lead to register for another webinar.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
        'long_term_homebuyer_tip' => [
            'description' => 'Long-term homebuyer nurture email.',
            'dispatch_key' => 'campaign_step_due',
            'status' => 'recommended',
        ],
    ],

    'automation_event_keys' => [
        'webinar.registered' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A lead registered for a webinar.',
            'status' => 'active',
        ],
        'webinar.cancelled' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A lead cancelled a webinar registration.',
            'status' => 'active',
        ],
        'webinar.attended' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A lead attended a webinar.',
            'status' => 'active',
        ],
        'webinar.missed' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A registered lead missed a webinar.',
            'status' => 'active',
        ],
        'webinar.ended' => [
            'producer' => 'webinars',
            'contact_required' => false,
            'description' => 'A webinar ended. May be contactless.',
            'status' => 'active',
        ],
        'task.completed' => [
            'producer' => 'tasks',
            'contact_required' => false,
            'description' => 'A task was completed.',
            'status' => 'active',
        ],
    ],

    'campaign_keys' => [
        'webinar_attended_nurture' => [
            'description' => 'Default nurture campaign for leads who attended a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
        ],
        'webinar_missed_nurture' => [
            'description' => 'Default nurture campaign for leads who missed a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
        ],
        'long_term_homebuyer_nurture' => [
            'description' => 'Long-term homebuyer education/re-engagement nurture.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
        ],
    ],

    'flow_route_keys' => [
        'webinar_attended_to_nurture' => [
            'description' => 'Route from webinar.attended to attended nurture campaign enrollment.',
            'trigger' => 'webinar.attended',
            'status' => 'recommended',
        ],
        'webinar_missed_to_nurture' => [
            'description' => 'Route from webinar.missed to missed nurture campaign enrollment.',
            'trigger' => 'webinar.missed',
            'status' => 'recommended',
        ],
    ],

    'point_types' => [
        'noop',
        'wait',
        'condition',
        'branch_evaluate',
        'event_wait',
        'create_task',
        'send_message',
        'change_status',
        'enroll_campaign',
        'cancel_campaign',
    ],

    'task_template_keys' => [
        'call_lead' => [
            'description' => 'Call the lead and record the outcome.',
            'status' => 'recommended',
        ],
        'review_lead_notes' => [
            'description' => 'Review lead history and determine the next best action.',
            'status' => 'recommended',
        ],
    ],

    'future_keys' => [
        'dispatch_keys' => [
            'appointment_scheduled' => 'A lead appointment/consultation was scheduled.',
            'appointment_cancelled' => 'A lead appointment/consultation was cancelled.',
            'application_started' => 'A lead started an application.',
            'application_submitted' => 'A lead submitted an application.',
            'document_requested' => 'A document was requested from a lead or third party.',
            'document_received' => 'A document was received.',
            'task_created' => 'A task was created and a notification may be needed.',
            'task_due' => 'A task is due soon.',
            'task_overdue' => 'A task is overdue.',
            'task_completed' => 'A task was completed and follow-up may be needed.',
        ],
        'automation_event_keys' => [
            'appointment.scheduled',
            'appointment.cancelled',
            'application.started',
            'application.submitted',
            'document.requested',
            'document.received',
            'inbound_message.received',
        ],
        'scopes' => [
            'appointments',
            'applications',
            'documents',
            'mortgage_nurture',
        ],
    ],

    'client_extension' => [
        'path_pattern' => 'client/{client-key}/config/reference/keys.php',
        'rules' => [
            'Client keys may add dispatch_keys, message_types, campaign_keys, flow_route_keys, task_template_keys, and token contexts.',
            'Client-specific keys should use clear namespacing when they are not reusable across clients.',
            'Do not redefine a core key to mean different behavior.',
            'If a client key becomes generally useful, promote it into this core registry later.',
        ],
        'example_prefixes' => [
            'slam_dunk.va_buyer_follow_up',
            'slam_dunk.credit_review_requested',
            'rob_mortgage_coach.bridge_loan_follow_up',
        ],
    ],

];
