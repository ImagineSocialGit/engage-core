<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\MessageConsentRevoked;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Rules\ConsentRevocationRules;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RevokeMessageConsentAction
{
    public function __construct(
        private readonly ConsentDomainRegistry $consentDomainRegistry,
        private readonly MessageConsentStateResolver $stateResolver,
    ) {}

    /**
     * @return array{revocations: Collection<int, ConsentRevocation>, created: bool}
     *
     * @throws ValidationException
     */
    public function handle(Contact $contact, array $data): array
    {
        $validated = Validator::make($data, ConsentRevocationRules::rules())->validate();

        return DB::transaction(function () use ($contact, $validated): array {
            $scope = isset($validated['scope'])
                ? $this->consentDomainRegistry->domainForScope($validated['scope'])
                : null;

            if ($scope !== null) {
                $result = $this->revokeScope($contact, $validated, $scope);

                $this->dispatchRevokedEventAfterCommit(
                    contact: $contact,
                    revocation: $result['revocation'],
                    created: $result['created'],
                    validated: $validated,
                    scope: $scope,
                );

                return [
                    'revocations' => new Collection([$result['revocation']]),
                    'created' => $result['created'],
                ];
            }

            $scopes = collect(array_keys($this->consentDomainRegistry->definitions()))
                ->merge(
                    MessageConsent::query()
                        ->where('contact_id', $contact->getKey())
                        ->where('channel', $validated['channel'])
                        ->where('purpose', $validated['purpose'])
                        ->pluck('scope')
                )
                ->filter(
                    fn (mixed $scope): bool => is_string($scope)
                        && trim($scope) !== ''
                )
                ->map(
                    fn (string $scope): string => $this->consentDomainRegistry
                        ->domainForScope($scope)
                )
                ->unique()
                ->values();

            $revocations = new Collection();
            $created = false;

            foreach ($scopes as $resolvedScope) {
                $result = $this->revokeScope($contact, $validated, $resolvedScope);

                $revocations->push($result['revocation']);
                $created = $created || $result['created'];

                $this->dispatchRevokedEventAfterCommit(
                    contact: $contact,
                    revocation: $result['revocation'],
                    created: $result['created'],
                    validated: $validated,
                    scope: $resolvedScope,
                );
            }

            return [
                'revocations' => $revocations,
                'created' => $created,
            ];
        });
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{revocation: ConsentRevocation, created: bool}
     */
    private function revokeScope(Contact $contact, array $validated, string $scope): array
    {
        $revokedAt = $validated['revoked_at'] ?? now();

        $latestConsent = $this->stateResolver->latestConsent(
            contact: $contact,
            channel: $validated['channel'],
            purpose: $validated['purpose'],
            scope: $scope,
        );

        $existingRevocation = ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $validated['channel'])
            ->where('purpose', $validated['purpose'])
            ->where('scope', $scope)
            ->when(
                $latestConsent,
                fn ($query) => $query->where('revoked_at', '>=', $latestConsent->consented_at),
            )
            ->orderByDesc('revoked_at')
            ->orderByDesc('id')
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

    /**
     * @param array<string, mixed> $validated
     */
    private function dispatchRevokedEventAfterCommit(
        Contact $contact,
        ConsentRevocation $revocation,
        bool $created,
        array $validated,
        string $scope,
    ): void {
        if (! $created) {
            return;
        }

        DB::afterCommit(function () use ($contact, $revocation, $validated, $scope): void {
            MessageConsentRevoked::dispatch(
                contact: $contact,
                consentRevocation: $revocation,
                channel: $validated['channel'],
                purpose: $validated['purpose'],
                scope: $scope,
                data: [
                    'reason' => $validated['reason'],
                    'source' => $validated['source'] ?? null,
                    'ip_address' => $validated['ip_address'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                    'meta' => $validated['meta'] ?? null,
                ],
            );
        });
    }
}
