<?php

namespace App\Services\Zoom;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ZoomOAuthService
{
    public function getAccessToken(): string
    {
        return Cache::remember('zoom_access_token', 3500, function () {

            $response = Http::asForm()
                ->withBasicAuth(
                    config('services.zoom.client_id'),
                    config('services.zoom.client_secret')
                )
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('services.zoom.account_id'),
                ]);

            return $response->json('access_token');
        });
    }
}