<?php

namespace App\Integrations\Messaging\Email\Resend;

use Illuminate\Support\Carbon;

class ResendWebhookVerifier
{
    /**
     * @param array<string, mixed> $headers
     */
    public function isValid(string $payload, array $headers): bool
    {
        $eventId = $this->header($headers, 'svix-id');
        $timestamp = $this->header($headers, 'svix-timestamp');
        $signature = $this->header($headers, 'svix-signature');
        $secrets = $this->configuredSecrets(
            config('services.resend.webhook_secret'),
        );

        if (
            $eventId === null
            || $timestamp === null
            || $signature === null
            || $secrets === []
        ) {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        foreach ($secrets as $secret) {
            if ($this->signatureMatches(
                eventId: $eventId,
                timestamp: $timestamp,
                payload: $payload,
                signatureHeader: $signature,
                secret: $secret,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $driftSeconds = (int) config('services.resend.webhook_timestamp_drift_seconds', 300);

        return abs(Carbon::now()->getTimestamp() - (int) $timestamp) <= $driftSeconds;
    }

    private function signatureMatches(
        string $eventId,
        string $timestamp,
        string $payload,
        string $signatureHeader,
        string $secret,
    ): bool {
        $secret = $this->normalizeSecret($secret);

        if ($secret === null) {
            return false;
        }

        $signedPayload = $eventId.'.'.$timestamp.'.'.$payload;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secret, true));

        foreach ($this->signatures($signatureHeader) as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSecret(string $secret): ?string
    {
        $secret = trim($secret);

        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);

            return $decoded === false ? null : $decoded;
        }

        return $secret !== '' ? $secret : null;
    }

    /**
     * @return array<int, string>
     */
    private function signatures(string $signatureHeader): array
    {
        return collect(explode(' ', $signatureHeader))
            ->map(fn (string $signature): string => trim($signature))
            ->filter()
            ->map(function (string $signature): ?string {
                if (! str_contains($signature, ',')) {
                    return $signature;
                }

                [$version, $value] = explode(',', $signature, 2);

                return $version === 'v1' ? $value : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function configuredSecrets(mixed $configured): array
    {
        $values = is_array($configured) ? $configured : [$configured];
        $secrets = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $secret) {
                $secret = trim($secret);

                if ($secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }

        return array_values(array_unique($secrets));
    }
}