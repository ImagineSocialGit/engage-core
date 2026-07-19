<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'webhook_timestamp_drift_seconds' => env('RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS', 300),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'virtual_phone' => env('TWILIO_VIRTUAL_PHONE'),
    ],

    'telnyx' => [
        'api_key' => env('TELNYX_API_KEY'),
        'from' => env('TELNYX_FROM'),
        'webhook_public_key' => env('TELNYX_WEBHOOK_PUBLIC_KEY'),
        'max_timestamp_drift_seconds' => env('TELNYX_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS', 300),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
        'max_timestamp_drift_seconds' => env('ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS', 300),
    ],

];
