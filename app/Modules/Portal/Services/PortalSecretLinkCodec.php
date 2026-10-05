<?php

namespace App\Modules\Portal\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

final class PortalSecretLinkCodec
{
    public const PURPOSE_INVITATION = 'portal_invitation';
    public const PURPOSE_PASSWORD_RESET = 'portal_password_reset';

    private const PREFIX = 'e1_';

    public function encode(string $purpose, string $secret): string
    {
        $purpose = $this->purpose($purpose);
        $secret = $this->secret($secret);

        try {
            $payload = json_encode([
                'version' => 1,
                'purpose' => $purpose,
                'secret' => $secret,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Portal secret link payload could not be encoded.',
                previous: $exception,
            );
        }

        $encrypted = Crypt::encryptString($payload);
        $urlSafe = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');

        return self::PREFIX.$urlSafe;
    }

    public function decode(string $value, string $expectedPurpose): string
    {
        $expectedPurpose = $this->purpose($expectedPurpose);
        $value = trim($value);

        if (! str_starts_with($value, self::PREFIX)) {
            return $this->secret($value);
        }

        $encoded = substr($value, strlen(self::PREFIX));
        $padding = strlen($encoded) % 4;

        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $encrypted = base64_decode(strtr($encoded, '-_', '+/'), true);

        if (! is_string($encrypted) || $encrypted === '') {
            throw new InvalidArgumentException('Portal secret link is invalid.');
        }

        try {
            $decoded = Crypt::decryptString($encrypted);
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('Portal secret link is invalid.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['purpose'] ?? null) !== $expectedPurpose
            || ! is_string($payload['secret'] ?? null)
        ) {
            throw new InvalidArgumentException('Portal secret link is invalid.');
        }

        return $this->secret($payload['secret']);
    }

    private function purpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));

        if (! in_array($purpose, [
            self::PURPOSE_INVITATION,
            self::PURPOSE_PASSWORD_RESET,
        ], true)) {
            throw new InvalidArgumentException('Portal secret link purpose is invalid.');
        }

        return $purpose;
    }

    private function secret(string $secret): string
    {
        $secret = trim($secret);

        if ($secret === '' || strlen($secret) > 2048) {
            throw new InvalidArgumentException('Portal secret link value is invalid.');
        }

        return $secret;
    }
}