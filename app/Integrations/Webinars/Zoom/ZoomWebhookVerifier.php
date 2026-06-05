<?php

namespace App\Integrations\Webinars\Zoom;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ZoomWebhookVerifier
{
    public function urlValidationResponse(Request $request): array
    {
        $plainToken = (string) $request->input('payload.plainToken');

        return [
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac(
                'sha256',
                $plainToken,
                (string) config('services.zoom.webhook_secret')
            ),
        ];
    }

    public function hasValidSignature(Request $request): bool
    {
        $secret = config('services.zoom.webhook_secret');

        if (! filled($secret)) {
            return false;
        }

        $timestamp = (string) $request->header('x-zm-request-timestamp');
        $signature = (string) $request->header('x-zm-signature');

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $maxDrift = (int) config(
            'services.zoom.max_timestamp_drift_seconds',
            300
        );

        if (abs(time() - (int) $timestamp) > $maxDrift) {
            return false;
        }

        $message = 'v0:'.$timestamp.':'.$request->getContent();

        $expected = 'v0='.hash_hmac(
            'sha256',
            $message,
            $secret
        );

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $fingerprint = hash(
            'sha256',
            $timestamp.'|'.$signature.'|'.$request->getContent()
        );

        $cacheKey = 'zoom:webhook:replay:'.$fingerprint;

        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put(
            $cacheKey,
            true,
            now()->addSeconds(
                (int) config(
                    'services.zoom.replay_cache_ttl_seconds',
                    600
                )
            )
        );

        return true;
    }
}