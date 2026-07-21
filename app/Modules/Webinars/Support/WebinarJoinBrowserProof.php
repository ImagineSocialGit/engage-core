<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use LogicException;
use Stringable;

class WebinarJoinBrowserProof
{
    private const SIGNING_CONTEXT = 'engage_core:webinar_join_browser_proof';

    public function issue(WebinarRegistration $registration): string
    {
        $registration->loadMissing('webinar');

        $registrationId = $registration->getKey();
        $joinToken = $this->joinToken($registration);

        if (
            ! is_numeric($registrationId)
            || $joinToken === null
        ) {
            throw new LogicException(
                'A persisted WebinarRegistration with a join token is required to issue a browser proof.',
            );
        }

        $version = $this->version();
        $expiresAt = $this->expiresAt($registration)->getTimestamp();
        $encodedExpiration = $this->encodeExpiration($expiresAt);
        $tag = $this->authenticationTag(
            version: $version,
            expiresAt: $expiresAt,
            registrationId: (string) $registrationId,
            joinToken: $joinToken,
        );

        return implode('.', [
            'v'.$version,
            $encodedExpiration,
            $this->base64UrlEncode($tag),
        ]);
    }

    public function validFor(
        string $proof,
        WebinarRegistration $registration,
    ): bool {
        $parsed = $this->parse($proof);

        if ($parsed === null) {
            return false;
        }

        if ($parsed['expires_at'] < now()->getTimestamp()) {
            return false;
        }

        $registrationId = $registration->getKey();
        $joinToken = $this->joinToken($registration);

        if (
            ! is_numeric($registrationId)
            || $joinToken === null
        ) {
            return false;
        }

        $expectedTag = $this->authenticationTag(
            version: $parsed['version'],
            expiresAt: $parsed['expires_at'],
            registrationId: (string) $registrationId,
            joinToken: $joinToken,
        );

        return hash_equals($expectedTag, $parsed['tag']);
    }

    private function joinToken(
        WebinarRegistration $registration,
    ): ?string {
        $value = $registration->join_token;

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if ($value instanceof Stringable) {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @return array{version: int, expires_at: int, tag: string}|null
     */
    private function parse(string $proof): ?array
    {
        $segments = explode('.', trim($proof));

        if (count($segments) !== 3) {
            return null;
        }

        [$versionSegment, $expirationSegment, $tagSegment] = $segments;

        if (preg_match('/^v([1-9][0-9]*)$/D', $versionSegment, $matches) !== 1) {
            return null;
        }

        $version = (int) $matches[1];

        if ($version !== $this->version()) {
            return null;
        }

        $expiresAt = $this->decodeExpiration($expirationSegment);

        if ($expiresAt === null) {
            return null;
        }

        $tag = $this->base64UrlDecode($tagSegment);

        if ($tag === null || strlen($tag) !== $this->tagBytes()) {
            return null;
        }

        return [
            'version' => $version,
            'expires_at' => $expiresAt,
            'tag' => $tag,
        ];
    }

    private function authenticationTag(
        int $version,
        int $expiresAt,
        string $registrationId,
        string $joinToken,
    ): string {
        $payload = implode("\n", [
            self::SIGNING_CONTEXT,
            'v'.$version,
            $registrationId,
            $joinToken,
            (string) $expiresAt,
        ]);

        return substr(
            hash_hmac('sha256', $payload, $this->signingKey(), true),
            0,
            $this->tagBytes(),
        );
    }

    private function signingKey(): string
    {
        $configuredKey = config('app.key');

        if (! is_string($configuredKey) || trim($configuredKey) === '') {
            throw new LogicException(
                'APP_KEY is required to sign Webinar browser proofs.',
            );
        }

        $configuredKey = trim($configuredKey);

        if (! str_starts_with($configuredKey, 'base64:')) {
            return $configuredKey;
        }

        $decoded = base64_decode(substr($configuredKey, 7), true);

        if (! is_string($decoded) || $decoded === '') {
            throw new LogicException(
                'APP_KEY contains invalid base64 data.',
            );
        }

        return $decoded;
    }

    private function encodeExpiration(int $timestamp): string
    {
        return str_pad(
            strtolower(dechex($timestamp)),
            8,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function decodeExpiration(string $encoded): ?int
    {
        if (preg_match('/^[0-9a-f]{8}$/D', $encoded) !== 1) {
            return null;
        }

        $timestamp = hexdec($encoded);

        if (! is_int($timestamp) && ! is_float($timestamp)) {
            return null;
        }

        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? $timestamp : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '=',
        );
    }

    private function base64UrlDecode(string $value): ?string
    {
        if (
            $value === ''
            || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1
        ) {
            return null;
        }

        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/'),
            true,
        );

        return is_string($decoded) ? $decoded : null;
    }

    private function version(): int
    {
        return max(
            1,
            (int) config(
                'webinars.registration.join_confirmation.browser_proof_version',
                1,
            ),
        );
    }

    private function tagBytes(): int
    {
        return min(
            32,
            max(
                16,
                (int) config(
                    'webinars.registration.join_confirmation.browser_proof_tag_bytes',
                    16,
                ),
            ),
        );
    }

    private function expiresAt(
        WebinarRegistration $registration,
    ): Carbon {
        $graceMinutes = max(
            1,
            (int) config(
                'webinars.registration.join_confirmation.browser_proof_grace_minutes',
                1440,
            ),
        );

        $fallbackDays = max(
            1,
            (int) config(
                'webinars.registration.join_confirmation.browser_proof_fallback_days',
                30,
            ),
        );

        $webinarEndsAt = $registration->webinar?->ends_at;

        if ($webinarEndsAt) {
            $eventExpiry = $webinarEndsAt
                ->copy()
                ->addMinutes($graceMinutes);

            if ($eventExpiry->isFuture()) {
                return $eventExpiry;
            }
        }

        return now()->addDays($fallbackDays);
    }
}