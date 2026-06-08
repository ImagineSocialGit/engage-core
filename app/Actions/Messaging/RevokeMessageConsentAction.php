<?php

namespace App\Actions\Messaging;

use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\MessageConsent;
use App\Rules\Messaging\ConsentRevocationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RevokeMessageConsentAction
{
    /**
     * @return array{revocation: ConsentRevocation, created: bool}
     *
     * @throws ValidationException
     */
    public function handle(Contact $contact, array $data): array
    {
        $validated = Validator::make($data, ConsentRevocationRules::rules())->validate();

        return DB::transaction(function () use ($contact, $validated): array {
            $now = now();
            $revokedAt = $validated['revoked_at'] ?? $now;

            $latestConsent = MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', $validated['channel'])
                ->where('purpose', $validated['purpose'])
                ->where('scope', $validated['scope'])
                ->latest('consented_at')
                ->first();

            $existingRevocation = ConsentRevocation::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', $validated['channel'])
                ->where('purpose', $validated['purpose'])
                ->where('scope', $validated['scope'])
                ->when($latestConsent, function ($query) use ($latestConsent) {
                    $query->where('revoked_at', '>=', $latestConsent->consented_at);
                })
                ->latest('revoked_at')
                ->first();

            if ($existingRevocation) {
                return [
                    'revocation' => $existingRevocation,
                    'created' => false,
                ];
            }

            return [
                'revocation' => ConsentRevocation::query()->create([
                    'contact_id' => $contact->getKey(),
                    'message_consent_id' => $validated['message_consent_id'] ?? $latestConsent?->id,
                    'channel' => $validated['channel'],
                    'purpose' => $validated['purpose'],
                    'scope' => $validated['scope'],
                    'reason' => $validated['reason'],
                    'revoked_at' => $revokedAt,
                    'source' => $validated['source'] ?? null,
                    'ip_address' => $validated['ip_address'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                    'meta' => $validated['meta'] ?? null,
                ]),
                'created' => true,
            ];
        });
    }
}