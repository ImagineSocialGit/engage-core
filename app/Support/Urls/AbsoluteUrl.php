<?php

namespace App\Support\Urls;

use InvalidArgumentException;

final class AbsoluteUrl
{
    public static function origin(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                'A non-empty absolute HTTP or HTTPS origin is required.',
            );
        }

        $value = trim($value);

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || str_contains($value, '\\')) {
            throw new InvalidArgumentException(
                'The URL origin contains invalid characters.',
            );
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            throw new InvalidArgumentException(
                'The URL origin could not be parsed.',
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'The URL origin must include an HTTP or HTTPS scheme.',
            );
        }

        if ($host === '') {
            throw new InvalidArgumentException(
                'The URL origin must include a host.',
            );
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            throw new InvalidArgumentException(
                'The URL origin may not include credentials, a path, a query string, or a fragment.',
            );
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
            throw new InvalidArgumentException(
                'The URL origin contains an invalid port.',
            );
        }

        return $scheme.'://'.strtolower($host).(
            is_int($port) ? ':'.$port : ''
        );
    }

    public static function join(mixed $origin, string $path): string
    {
        $origin = self::origin($origin);
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException(
                'A non-empty relative URL path is required.',
            );
        }

        if (
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '\\')
            || str_starts_with($path, '//')
        ) {
            throw new InvalidArgumentException(
                'The relative URL path contains invalid characters.',
            );
        }

        $parts = parse_url($path);

        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            throw new InvalidArgumentException(
                'The URL path must be relative to the supplied origin.',
            );
        }

        return $origin.'/'.ltrim($path, '/');
    }
}