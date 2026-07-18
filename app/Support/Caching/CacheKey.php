<?php

namespace App\Support\Caching;

class CacheKey
{
    private static function client(): string
    {
        return (string) config('client.key', 'default');
    }

    public static function nextUpcomingWebinar(?string $seriesSlug = null): string
    {
        return implode(':', [
            'webinars',
            self::client(),
            'next-upcoming',
            $seriesSlug ?: 'default',
        ]);
    }

    public static function activeWebinarSeries(): string
    {
        return implode(':', [
            'webinars',
            self::client(),
            'active-series',
        ]);
    }

    public static function zoomOAuthToken(string $accountKey = 'default'): string
    {
        return 'integrations:zoom:oauth-token:' . $accountKey;
    }

    public static function externalApiResponse(
        string $provider,
        string $resource,
        string $identifier
    ): string {
        return 'external-api:' . $provider . ':' . $resource . ':' . sha1($identifier);
    }

    public static function imageManifest(?string $namespace = null): string
    {
        return implode(':', [
            'assets',
            self::client(),
            'image-manifest',
            $namespace ?: 'default',
        ]);
    }
}
