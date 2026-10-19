<?php

$rootDomain = trim((string) env('ROOT_DOMAIN', 'localhost'));
$appUrl = trim((string) env('APP_URL', 'http://localhost'));
$appScheme = parse_url($appUrl, PHP_URL_SCHEME);
$appPort = parse_url($appUrl, PHP_URL_PORT);

$defaultPublicUrl = sprintf(
    '%s://forms.%s%s',
    is_string($appScheme) && $appScheme !== ''
        ? strtolower($appScheme)
        : 'http',
    $rootDomain !== '' ? $rootDomain : 'localhost',
    is_int($appPort) ? ':'.$appPort : '',
);

$configuredPublicUrl = env('FORMS_APP_URL');
$publicUrlConfigured = is_string($configuredPublicUrl)
    && trim($configuredPublicUrl) !== '';
$publicUrl = $publicUrlConfigured
    ? trim($configuredPublicUrl)
    : $defaultPublicUrl;
$normalizedPublicUrl = null;
$publicHost = null;
$publicScheme = null;

$parts = parse_url($publicUrl);
$scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
    ? strtolower(trim($parts['scheme']))
    : null;
$host = is_array($parts) && is_string($parts['host'] ?? null)
    ? strtolower(trim($parts['host']))
    : null;
$path = is_array($parts) && is_string($parts['path'] ?? null)
    ? trim($parts['path'])
    : '';
$port = is_array($parts) && is_int($parts['port'] ?? null)
    ? $parts['port']
    : null;
$hasUnsupportedParts = is_array($parts) && (
    array_key_exists('user', $parts)
    || array_key_exists('pass', $parts)
    || array_key_exists('query', $parts)
    || array_key_exists('fragment', $parts)
);

if (in_array($scheme, ['http', 'https'], true)
    && is_string($host)
    && $host !== ''
    && in_array($path, ['', '/'], true)
    && ! $hasUnsupportedParts
) {
    $publicScheme = $scheme;
    $publicHost = $host;
    $normalizedPublicUrl = $scheme.'://'.$host.($port !== null ? ':'.$port : '');
}

$clientId = trim((string) env('FORMS_EXTERNAL_INTAKE_CLIENT_ID', ''));
$allowedForms = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS', '')),
)));
$configuredDomains = env('FORMS_EXTERNAL_INTAKE_DOMAINS');
$domainList = is_string($configuredDomains) && trim($configuredDomains) !== ''
    ? $configuredDomains
    : (string) env('ROOT_DOMAIN', '');
$domains = array_values(array_filter(array_map(
    'trim',
    explode(',', $domainList),
)));

$clients = $clientId === '' ? [] : [
    $clientId => [
        'secret' => env('FORMS_EXTERNAL_INTAKE_CLIENT_SECRET'),
        'source' => env('FORMS_EXTERNAL_INTAKE_SOURCE', $clientId),
        'provider' => env('FORMS_EXTERNAL_INTAKE_PROVIDER', $clientId),
        'allowed_forms' => $allowedForms,
        'domains' => $domains,
    ],
];

return [
    'public' => [
        'url_configured' => $publicUrlConfigured,
        'url' => $normalizedPublicUrl,
        'host' => $publicHost,
        'scheme' => $publicScheme,
        'submission_rate_limit_per_minute' => 20,
    ],

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