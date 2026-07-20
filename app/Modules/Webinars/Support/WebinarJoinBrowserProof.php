<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class WebinarJoinBrowserProof
{
    public function issue(WebinarRegistration $registration): string
    {
        $registration->loadMissing('webinar');

        return Crypt::encryptString(json_encode([
            'version' => 1,
            'registration_id' => (int) $registration->getKey(),
            'join_token' => (string) $registration->join_token,
            'expires_at' => $this->expiresAt($registration)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function validFor(
        string $proof,
        WebinarRegistration $registration,
    ): bool {
        try {
            $payload = json_decode(
                Crypt::decryptString($proof),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        $registrationId = $payload['registration_id'] ?? null;
        $joinToken = $payload['join_token'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (
            ! is_numeric($registrationId)
            || (int) $registrationId !== (int) $registration->getKey()
            || ! is_string($joinToken)
            || ! is_string($registration->join_token)
            || ! hash_equals($registration->join_token, $joinToken)
            || ! is_numeric($expiresAt)
        ) {
            return false;
        }

        return (int) $expiresAt >= now()->getTimestamp();
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
            $eventExpiry = $webinarEndsAt->copy()->addMinutes($graceMinutes);

            if ($eventExpiry->isFuture()) {
                return $eventExpiry;
            }
        }

        return now()->addDays($fallbackDays);
    }
}