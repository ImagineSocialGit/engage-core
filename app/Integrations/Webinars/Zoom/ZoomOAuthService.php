<?php

namespace App\Integrations\Webinars\Zoom;

use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZoomOAuthService
{
    public function getAccessToken(): string
    {
        $provider = config('webinars.provider');

        if (! is_string($provider) || $provider === '') {
            throw new RuntimeException('No webinar provider is configured.');
        }

        $accountId = config('services.zoom.account_id');
        $clientId = config('services.zoom.client_id');
        $clientSecret = config('services.zoom.client_secret');

        if (! is_string($accountId) || $accountId === '') {
            throw new RuntimeException('Zoom account ID is not configured.');
        }

        if (! is_string($clientId) || $clientId === '') {
            throw new RuntimeException('Zoom client ID is not configured.');
        }

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('Zoom client secret is not configured.');
        }

        $cacheKey = CacheKey::zoomOAuthToken($this->cacheIdentity($provider, $accountId, $clientId));
        $ttl = (int) config("webinars.providers.{$provider}.oauth_token_ttl_seconds");
        $oauthUrl = config("webinars.providers.{$provider}.oauth_url");

        if (! is_string($oauthUrl) || $oauthUrl === '') {
            throw new RuntimeException("OAuth URL is not configured for webinar provider [{$provider}].");
        }

        return Cache::remember($cacheKey, $ttl, function () use ($oauthUrl, $clientId, $clientSecret, $accountId): string {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->post($oauthUrl, [
                    'grant_type' => 'account_credentials',
                    'account_id' => $accountId,
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

    private function cacheIdentity(string $provider, string $accountId, string $clientId): string
    {
        return $provider.':'.sha1($accountId.'|'.$clientId);
    }
}