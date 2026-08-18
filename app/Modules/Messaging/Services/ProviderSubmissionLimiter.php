<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

class ProviderSubmissionLimiter
{
    public function acquire(string $channel, string $provider): void
    {
        $channel = $this->normalizedKey($channel);
        $provider = $this->normalizedKey($provider);

        if ($channel === '' || $provider === '' || app()->environment('local')) {
            return;
        }

        $settings = config("messaging.delivery.provider_rate_limits.{$channel}.{$provider}");

        if (! is_array($settings) || ! (bool) ($settings['enabled'] ?? false)) {
            return;
        }

        $maxRequests = min(10000, max(1, (int) ($settings['max_requests'] ?? 1)));
        $decaySeconds = min(60, max(1, (int) ($settings['decay_seconds'] ?? 1)));
        $scope = $this->normalizedKey($settings['scope'] ?? 'default') ?: 'default';
        $store = trim((string) config(
            'messaging.delivery.provider_rate_limits.cache_store',
            config('cache.default', 'redis'),
        ));

        if ($store === '') {
            $store = 'redis';
        }

        $limiter = new RateLimiter(Cache::store($store));
        $key = implode(':', [
            'messaging',
            'provider_submission',
            $channel,
            $provider,
            $scope,
        ]);

        while ($limiter->attempt(
            key: $key,
            maxAttempts: $maxRequests,
            callback: static fn (): bool => true,
            decaySeconds: $decaySeconds,
        ) !== true) {
            Sleep::for(max(1, $limiter->availableIn($key)))->seconds();
        }
    }

    private function normalizedKey(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_.:-]+/', '_', $normalized);

        return is_string($normalized)
            ? trim($normalized, '_')
            : '';
    }
}