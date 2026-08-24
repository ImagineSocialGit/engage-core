<?php

namespace App\Modules\Forms\Services;

use InvalidArgumentException;

final class ExternalFormIntakeSecretPolicy
{
    public const MIN_BYTES = 32;

    public const MAX_BYTES = 4096;

    private const GENERATED_RANDOM_BYTES = 48;

    public function generate(): string
    {
        return rtrim(strtr(
            base64_encode(random_bytes(self::GENERATED_RANDOM_BYTES)),
            '+/',
            '-_',
        ), '=');
    }

    public function validatedSecret(mixed $secret, string $clientId): string
    {
        if (! is_string($secret)
            || strlen($secret) < self::MIN_BYTES
            || strlen($secret) > self::MAX_BYTES
        ) {
            throw new InvalidArgumentException(sprintf(
                'External Forms intake client [%s] secret must contain between %d and %d bytes.',
                $clientId,
                self::MIN_BYTES,
                self::MAX_BYTES,
            ));
        }

        return $secret;
    }
}