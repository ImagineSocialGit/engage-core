<?php

$publicUrl = env('SCHEDULING_APP_URL');
$normalizedPublicUrl = null;
$publicHost = null;
$publicScheme = null;

if (is_string($publicUrl) && trim($publicUrl) !== '') {
    $candidate = trim($publicUrl);
    $parts = parse_url($candidate);

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
}

return [

    'public' => [
        'enabled' => $normalizedPublicUrl !== null,
        'url' => $normalizedPublicUrl,
        'host' => $publicHost,
        'scheme' => $publicScheme,
        'availability_max_days' => 31,
    ],

    'slot_offers' => [
        'ttl_seconds' => 300,
    ],

    'booking_holds' => [
        'ttl_seconds' => 600,
        'expiration_batch_size' => 500,
    ],

];