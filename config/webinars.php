<?php

return [

    'enabled' => env('WEBINARS_ENABLED', true),

    'provider' => env('WEBINAR_PROVIDER', 'zoom'),

    'providers' => [
        'zoom' => [
            'provider' => App\Integrations\Webinars\Zoom\ZoomWebinarProvider::class,
            'base_url' => env('ZOOM_BASE_URL', 'https://api.zoom.us/v2'),
            'oauth_url' => env('ZOOM_OAUTH_URL', 'https://zoom.us/oauth/token'),
            'oauth_token_ttl_seconds' => env('ZOOM_OAUTH_TOKEN_TTL_SECONDS', 3500),

            'webhook_events' => [
                'webinar.ended' => 'webinar.ended',
                'webinar.completed' => 'webinar.ended',
                'recording.completed' => 'webinar.recording_completed',
            ],
        ],
    ],

    'queues' => [

        'registrations' => env('WEBINAR_REGISTRATION_QUEUE', 'webinars'),

        'webhooks' => env('WEBINAR_WEBHOOK_QUEUE', 'webhooks'),

        'notifications' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'reminders' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'confirmation_messages' => env('WEBINAR_CONFIRMATION_MESSAGE_QUEUE', 'notifications'),

        'followups' => env('WEBINAR_FOLLOWUP_QUEUE', 'notifications'),
    ],


    'schedule_profiles' => [

        'full_10_day' => [
            'name' => 'Full webinar schedule',
            'description' => 'Confirmation, full reminder timeline, waitlist alert, and transactional replay follow-ups.',
            'status' => 'active',
            'is_default' => true,
            'is_active' => true,
            'source_version' => 1,
            'items' => [
                ['key' => 'email_confirmation_delay_15', 'label' => 'Email confirmation', 'context_key' => 'confirmation', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'confirmation', 'dispatch_key' => 'registration_created', 'message_template_key' => 'confirmation', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0', 'timing' => 'scheduled', 'schedule' => ['type' => 'delay', 'minutes' => 15], 'sort_order' => 10, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_confirmation_delay_15', 'label' => 'SMS confirmation', 'context_key' => 'confirmation', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'confirmation', 'dispatch_key' => 'registration_created', 'message_template_key' => 'confirmation', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.confirmations.0', 'timing' => 'scheduled', 'schedule' => ['type' => 'delay', 'minutes' => 15], 'sort_order' => 20, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],

                ['key' => 'email_reminder_10_day', 'label' => 'Email 10-day reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_10_day', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -14400], 'sort_order' => 100, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_reminder_1_week', 'label' => 'Email 1-week reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_1_week', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.1', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -10080], 'sort_order' => 110, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_reminder_1_day', 'label' => 'Email 1-day reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_1_day', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.2', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -1440], 'sort_order' => 120, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_reminder_30_minute', 'label' => 'Email 30-minute reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_30_minute', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.3', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -30], 'sort_order' => 130, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_reminder_10_minute', 'label' => 'Email 10-minute reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_10_minute', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.4', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -10], 'sort_order' => 140, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_reminder_live', 'label' => 'Email live reminder', 'context_key' => 'reminders', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_live', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.5', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => 5], 'sort_order' => 150, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],

                ['key' => 'sms_reminder_10_day', 'label' => 'SMS 10-day reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_10_day', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.0', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -14400], 'sort_order' => 200, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_reminder_1_week', 'label' => 'SMS 1-week reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_1_week', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.1', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -10080], 'sort_order' => 210, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_reminder_1_day', 'label' => 'SMS 1-day reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_1_day', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.2', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -1440], 'sort_order' => 220, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_reminder_30_minute', 'label' => 'SMS 30-minute reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_30_minute', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.3', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -30], 'sort_order' => 230, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_reminder_10_minute', 'label' => 'SMS 10-minute reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_10_minute', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.4', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => -10], 'sort_order' => 240, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_reminder_live', 'label' => 'SMS live reminder', 'context_key' => 'reminders', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'reminder', 'dispatch_key' => 'registration_created', 'message_template_key' => 'reminder_live', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.reminders.5', 'timing' => 'scheduled', 'schedule' => ['type' => 'anchored', 'minutes' => 0], 'sort_order' => 250, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],

                ['key' => 'email_waitlist_alert', 'label' => 'Email waitlist alert', 'context_key' => 'waitlist', 'channel' => 'email', 'purpose' => 'marketing', 'scope' => 'webinar_waitlist', 'surface' => 'webinar_waitlists', 'message_type' => 'alert', 'dispatch_key' => 'webinar_added', 'message_template_key' => 'alert', 'source_config_path' => 'messaging.email.definitions.marketing.webinar_waitlist.alerts.0', 'timing' => 'immediate', 'sort_order' => 300, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_waitlist_alert', 'label' => 'SMS waitlist alert', 'context_key' => 'waitlist', 'channel' => 'sms', 'purpose' => 'marketing', 'scope' => 'webinar_waitlist', 'surface' => 'webinar_waitlists', 'message_type' => 'alert', 'dispatch_key' => 'webinar_added', 'message_template_key' => 'alert', 'source_config_path' => 'messaging.sms.definitions.marketing.webinar_waitlist.alerts.0', 'timing' => 'immediate', 'sort_order' => 310, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],

                ['key' => 'email_post_attended', 'label' => 'Email attended replay follow-up', 'context_key' => 'post_attended', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'post_attended', 'dispatch_key' => 'webinar_ended', 'message_template_key' => 'post_attended', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.post_attended.0', 'timing' => 'immediate', 'sort_order' => 400, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_post_attended', 'label' => 'SMS attended replay follow-up', 'context_key' => 'post_attended', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'post_attended', 'dispatch_key' => 'webinar_ended', 'message_template_key' => 'post_attended', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.post_attended.0', 'timing' => 'immediate', 'sort_order' => 410, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'email_post_missed', 'label' => 'Email missed replay follow-up', 'context_key' => 'post_missed', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'post_missed', 'dispatch_key' => 'webinar_ended', 'message_template_key' => 'post_missed', 'source_config_path' => 'messaging.email.definitions.transactional.webinar.post_missed.0', 'timing' => 'immediate', 'sort_order' => 420, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
                ['key' => 'sms_post_missed', 'label' => 'SMS missed replay follow-up', 'context_key' => 'post_missed', 'channel' => 'sms', 'purpose' => 'transactional', 'scope' => 'webinar', 'surface' => 'webinar_registrations', 'message_type' => 'post_missed', 'dispatch_key' => 'webinar_ended', 'message_template_key' => 'post_missed', 'source_config_path' => 'messaging.sms.definitions.transactional.webinar.post_missed.0', 'timing' => 'immediate', 'sort_order' => 430, 'conditions' => [], 'is_enabled' => true, 'is_active' => true, 'meta' => []],
            ],
        ],
    ],

    'registration' => [

        'require_unique_email_per_webinar' => true,

        'cooldowns' => [
            'per_email_minutes' => 15,
            'per_phone_minutes' => 15,
            'per_ip_minutes' => 15,
        ],

        'rate_limits' => [

            'per_ip_per_minute' => 400,

            'per_email_per_hour' => 500,
        ],
    ],

    'webhooks' => [

        'validate_signature' => true,

        'max_timestamp_drift_seconds' => 300,

        'replay_cache_ttl_seconds' => 600,
    ],

    'cache' => [

        'next_webinar_ttl_seconds' => 300,

        'webinar_page_ttl_seconds' => 300,
    ],

];
