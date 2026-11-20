<?php

/**
 * Static, bootstrap-safe environment ownership for this module.
 * Owner is derived from the module directory; requiredness remains governed
 * by the module's deployment contributor and the selected client roles.
 * Do not read env(), config(), or services from this file.
 */
return [
    ['key' => 'CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_REGISTRATION_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_WEBHOOK_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_REMINDER_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_CONFIRMATION_MESSAGE_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_FOLLOWUP_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'ZOOM_BASE_URL', 'scope' => 'root', 'secret' => false],
    ['key' => 'ZOOM_OAUTH_URL', 'scope' => 'root', 'secret' => false],
    ['key' => 'ZOOM_OAUTH_TOKEN_TTL_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'WEBINAR_APP_URL', 'scope' => 'client', 'secret' => false],
    ['key' => 'WEBINAR_PROVIDER', 'scope' => 'client', 'secret' => false],
    ['key' => 'ZOOM_ACCOUNT_ID', 'scope' => 'client', 'secret' => false],
    ['key' => 'ZOOM_CLIENT_ID', 'scope' => 'client', 'secret' => false],
    ['key' => 'ZOOM_CLIENT_SECRET', 'scope' => 'client', 'secret' => true],
    ['key' => 'ZOOM_WEBHOOK_SECRET', 'scope' => 'client', 'secret' => true],
];
