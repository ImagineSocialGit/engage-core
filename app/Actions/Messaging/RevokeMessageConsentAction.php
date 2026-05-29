<?php

namespace App\Actions\Messaging;

use App\Models\ConsentRevocation;
use App\Models\MessageConsent;
use App\Rules\Messaging\ConsentRevocationRules;
use Illuminate\Database\Eloquent\Model;
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
    public function handle(Model $recipient, array $data): array
    {
        $validated = Validator::make($data, ConsentRevocationRules::rules())->validate();

        return DB::transaction(function () use ($recipient, $validated): array {
            $now = now();
            $revokedAt = $validated['revoked_at'] ?? $now;

            $latestConsent = MessageConsent::query()
                ->whereMorphedTo('recipient', $recipient)
                ->where('channel', $validated['channel'])
                ->where('purpose', $validated['purpose'])
                ->latest('consented_at')
                ->first();

            $existingRevocation = ConsentRevocation::query()
                ->whereMorphedTo('recipient', $recipient)
                ->where('channel', $validated['channel'])
                ->where('purpose', $validated['purpose'])
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
                    'recipient_type' => $recipient->getMorphClass(),
                    'recipient_id' => $recipient->getKey(),
                    'message_consent_id' => $validated['message_consent_id'] ?? $latestConsent?->id,
                    'channel' => $validated['channel'],
                    'purpose' => $validated['purpose'],
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