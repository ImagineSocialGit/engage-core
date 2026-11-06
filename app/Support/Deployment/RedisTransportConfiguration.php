<?php

namespace App\Support\Deployment;

use InvalidArgumentException;

/**
 * Redis transport policy shared by the default and cache connections.
 *
 * Infrastructure assigns the endpoint, ACL principal and trusted CA. Core
 * consumes these values without provisioning server-level Redis resources.
 */
final class RedisTransportConfiguration
{
    /** @return array{scheme: string, context: array<string, mixed>|null} */
    public static function forEnvironment(
        string $environment,
        string $requestedScheme,
        string $host,
        ?string $caFile,
    ): array {
        if (trim($host) === '') {
            throw new InvalidArgumentException('Redis host must not be empty.');
        }

        // A missing or stale environment key must never silently downgrade
        // staging/production connections to unencrypted Redis transport.
        $scheme = in_array($environment, ['staging', 'production'], true)
            ? 'tls'
            : $requestedScheme;

        if (! in_array($scheme, ['tcp', 'tls'], true)) {
            throw new InvalidArgumentException('Unsupported Redis transport.');
        }

        if ($scheme === 'tcp') {
            return ['scheme' => 'tcp', 'context' => null];
        }

        $stream = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ];

        if (is_string($caFile) && trim($caFile) !== '') {
            $stream['cafile'] = $caFile;
        }

        return [
            'scheme' => 'tls',
            'context' => ['stream' => $stream],
        ];
    }
}