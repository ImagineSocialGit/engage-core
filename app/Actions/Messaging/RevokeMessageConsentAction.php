<?php

namespace App\Actions\Messaging;

use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\MessageConsent;
use App\Rules\Messaging\ConsentRevocationRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RevokeMessageConsentAction
{
    /**
     * @return array{revocations: Collection<int, ConsentRevocation>, created: bool}
     *
     * @throws ValidationException
     */
    public function handle(Contact $contact, array $data): array
    {
        $validated = Validator::make($data, ConsentRevocationRules::rules())->validate();

        return DB::transaction(function () use ($contact, $validated): array {
            $scope = $validated['scope'] ?? null;

            if ($scope !== null) {
                $result = $this->revokeScope($contact, $validated, $scope);

                return [
                    'revocations' => new Collection([$result['revocation']]),
                    'created' => $result['created'],
                ];
            }

            $scopes = MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', $validated['channel'])
                ->where('purpose', $validated['purpose'])
                ->pluck('scope')
                ->filter()
                ->unique()
                ->values();

            $revocations = new Collection();
            $created = false;

            foreach ($scopes as $scope) {
                $result = $this->revokeScope($contact, $validated, $scope);

                $revocations->push($result['revocation']);

                if ($result['created']) {
                    $created = true;
                }
            }

            return [
                'revocations' => $revocations,
                'created' => $created,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{revocation: ConsentRevocation, created: bool}
     */
    private function revokeScope(Contact $contact, array $validated, string $scope): array
    {
        $now = now();
        $revokedAt = $validated['revoked_at'] ?? $now;

        $latestConsent = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $validated['channel'])
            ->where('purpose', $validated['purpose'])
            ->where('scope', $scope)
            ->latest('consented_at')
            ->first();

        $existingRevocation = ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $validated['channel'])
            ->where('purpose', $validated['purpose'])
            ->where('scope', $scope)
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
                'scope' => $scope,
                'reason' => $validated['reason'],
                'revoked_at' => $revokedAt,
                'source' => $validated['source'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'user_agent' => $validated['user_agent'] ?? null,
                'meta' => $validated['meta'] ?? null,
            ]),
            'created' => true,
        ];
    }
}