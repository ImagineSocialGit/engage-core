<?php

namespace App\Support\Caching;

class CacheKey
{
    public static function nextUpcomingWebinar(?string $seriesSlug = null): string
    {
        return 'webinars:next-upcoming:' . ($seriesSlug ?: 'default');
    }

    public static function publicPageConfig(string $page, ?string $seriesSlug = null): string
    {
        return 'public-pages:' . $page . ':config:' . ($seriesSlug ?: 'default');
    }

    public static function webinarLandingPage(string $seriesSlug): string
    {
        return 'public-pages:webinar-registration:response:' . $seriesSlug;
    }

    public static function zoomOAuthToken(string $accountKey = 'default'): string
    {
        return 'integrations:zoom:oauth-token:' . $accountKey;
    }

    public static function externalApiResponse(string $provider, string $identifier): string
    {
        return 'external-api:' . $provider . ':' . sha1($identifier);
    }

    public static function imageManifest(?string $namespace = null): string
    {
        return 'assets:image-manifest:' . ($namespace ?: 'default');
    }
}