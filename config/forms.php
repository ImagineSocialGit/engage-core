<?php

$clientId = trim((string) env('FORMS_EXTERNAL_INTAKE_CLIENT_ID', ''));
$allowedForms = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS', '')),
)));

$clients = $clientId === '' ? [] : [
    $clientId => [
        'secret' => env('FORMS_EXTERNAL_INTAKE_CLIENT_SECRET'),
        'source' => env('FORMS_EXTERNAL_INTAKE_SOURCE', $clientId),
        'provider' => env('FORMS_EXTERNAL_INTAKE_PROVIDER', $clientId),
        'allowed_forms' => $allowedForms,
    ],
];

return [
    'external_intake' => [
        'enabled' => (bool) env('FORMS_EXTERNAL_INTAKE_ENABLED', false),
        'max_body_bytes' => (int) env('FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES', 262144),
        'max_timestamp_drift_seconds' => (int) env('FORMS_EXTERNAL_INTAKE_MAX_TIMESTAMP_DRIFT_SECONDS', 300),
        'nonce_ttl_seconds' => (int) env('FORMS_EXTERNAL_INTAKE_NONCE_TTL_SECONDS', 600),
        'unauthenticated_rate_limit_per_minute' => (int) env('FORMS_EXTERNAL_INTAKE_UNAUTHENTICATED_RATE_LIMIT_PER_MINUTE', 120),
        'client_rate_limit_per_minute' => (int) env('FORMS_EXTERNAL_INTAKE_CLIENT_RATE_LIMIT_PER_MINUTE', 60),
        'clients' => $clients,
    ],
];