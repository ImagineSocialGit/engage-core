<?php

namespace App\Support\Deployment\Credentials;

/**
 * Stable, purpose/caller-scoped selected-client environment key derivation.
 * This does not establish a caller slot or authorize credential delivery.
 */
final class CoreCredentialCallerKey
{
    private const PURPOSES = [
        'forms.external_intake' => ['FORMS_EXTERNAL_INTAKE_CALLER_', '_SECRET'],
        'donations.external_checkout' => ['DONATIONS_EXTERNAL_CHECKOUT_CALLER_', '_SIGNING_SECRET'],
    ];

    public static function forCaller(string $purpose, string $sourcePlatform, string $callerId): ?string
    {
        $route = self::PURPOSES[$purpose] ?? null;
        if ($route === null
            || ! in_array($sourcePlatform, ['seo', 'artist'], true)
            || ($purpose === 'donations.external_checkout' && $sourcePlatform !== 'seo')
            || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $callerId) !== 1
        ) {
            return null;
        }

        $digest = strtoupper(substr(hash('sha256', implode("\0", [
            'engage-core-recipient-v1', $purpose, $sourcePlatform, $callerId,
        ])), 0, 32));

        return $route[0].$digest.$route[1];
    }
}