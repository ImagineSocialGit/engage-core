<?php

namespace App\Support\HumanVerification;

use App\Support\HumanVerification\Data\HumanVerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;

final class PublicHumanVerificationGrantStore
{
    private const SESSION_KEY = 'public_human_verification.grants';

    public function hasValidGrant(
        Store $session,
        string $surface,
        string $hostname,
    ): bool {
        $grant = $this->storedGrant($session, $surface);

        if ($grant === null) {
            return false;
        }

        if (($grant['hostname'] ?? null) !== $this->normalizeHostname($hostname)) {
            return false;
        }

        $expiresAt = $grant['expires_at'] ?? null;

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    public function grant(
        Store $session,
        string $surface,
        string $hostname,
        HumanVerificationResult $result,
        int $ttlSeconds,
    ): void {
        if (! $result->passes() || ! $result->verified) {
            return;
        }

        $verifiedAt = $result->verifiedAt
            ? CarbonImmutable::instance($result->verifiedAt)
            : CarbonImmutable::now('UTC');

        $grants = $session->get(self::SESSION_KEY, []);
        $grants = is_array($grants) ? $grants : [];
        $grants[$surface] = [
            'provider' => $result->provider,
            'hostname' => $this->normalizeHostname($hostname),
            'verified_at' => $verifiedAt->toIso8601String(),
            'expires_at' => $verifiedAt
                ->addSeconds(max(60, $ttlSeconds))
                ->toIso8601String(),
        ];

        $session->put(self::SESSION_KEY, $grants);
    }

    /** @return array<string, mixed>|null */
    public function grantDetails(Store $session, string $surface): ?array
    {
        return $this->storedGrant($session, $surface);
    }

    /** @return array<string, mixed>|null */
    private function storedGrant(Store $session, string $surface): ?array
    {
        $grants = $session->get(self::SESSION_KEY, []);

        if (! is_array($grants)) {
            return null;
        }

        $grant = $grants[$surface] ?? null;

        return is_array($grant) ? $grant : null;
    }

    private function normalizeHostname(string $hostname): string
    {
        return strtolower(trim($hostname));
    }
}