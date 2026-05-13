<?php

namespace App\Services\Zoom;

use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZoomOAuthService
{
    public function getAccessToken(): string
    {
        $provider = config('webinars.provider');

        $cacheKey = CacheKey::zoomOAuthToken($provider);
        $ttl = (int) config("webinars.providers.{$provider}.oauth_token_ttl_seconds");
        $oauthUrl = config("webinars.providers.{$provider}.oauth_url");

        if (! is_string($oauthUrl) || $oauthUrl === '') {
            throw new RuntimeException("OAuth URL is not configured for webinar provider [{$provider}].");
        }

        return Cache::remember($cacheKey, $ttl, function () use ($oauthUrl): string {
            $response = Http::asForm()
                ->withBasicAuth(
                    config('services.zoom.client_id'),
                    config('services.zoom.client_secret')
                )
                ->post($oauthUrl, [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('services.zoom.account_id'),
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Unable to retrieve Zoom access token: '.$response->body()
                );
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Zoom access token response did not contain an access_token.');
            }

            return $token;
        });
    }
}