<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\MessageConsentRevoked;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Rules\ConsentRevocationRules;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RevokeMessageConsentAction
{
    private const FALLBACK_CAPTURE_SCOPE = 'channel_purpose';

    public function __construct(
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
        $channel = $this->normalizeSegment($validated['channel']);
        $purpose = $this->normalizeSegment($validated['purpose']);
        $requestedScope = isset($validated['scope'])
            ? $this->normalizeSegment($validated['scope'])
            : null;
        $revokedAt = isset($validated['revoked_at'])
            ? Carbon::parse($validated['revoked_at'])
            : now();

        return DB::transaction(function () use (
            $contact,
            $validated,
            $channel,
            $purpose,
            $requestedScope,
            $revokedAt,
        ): array {
            Contact::query()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $activeRevocation = $this->stateResolver->activeRevocation(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
            );

            if ($activeRevocation instanceof ConsentRevocation) {
                return [
                    'revocations' => new Collection([$activeRevocation]),
                    'created' => false,
                ];
            }

            $latestConsent = $this->stateResolver->latestConsent(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
            );
            $captureScope = $requestedScope
                ?? self::FALLBACK_CAPTURE_SCOPE;

            $revocation = ConsentRevocation::query()->create([
                'contact_id' => $contact->getKey(),
                'message_consent_id' => $validated['message_consent_id'] ?? $latestConsent?->id,
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $captureScope,
                'reason' => $validated['reason'],
                'revoked_at' => $revokedAt,
                'source' => $validated['source'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'user_agent' => $validated['user_agent'] ?? null,
                'meta' => array_replace_recursive(
                    is_array($validated['meta'] ?? null) ? $validated['meta'] : [],
                    [
                        'consent' => [
                            'permission_boundary' => 'channel_purpose',
                            'requested_scope' => $requestedScope,
                            'related_consent_scope' => $this->captureScopeFromConsent($latestConsent),
                        ],
                    ],
                ),
            ]);

            $this->dispatchRevokedEventAfterCommit(
                contact: $contact,
                revocation: $revocation,
                validated: $validated,
                requestedScope: $requestedScope,
            );

            return [
                'revocations' => new Collection([$revocation]),
                'created' => true,
            ];
        });
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function dispatchRevokedEventAfterCommit(
        Contact $contact,
        ConsentRevocation $revocation,
        array $validated,
        ?string $requestedScope,
    ): void {
        DB::afterCommit(function () use ($contact, $revocation, $validated, $requestedScope): void {
            MessageConsentRevoked::dispatch(
                contact: $contact,
                consentRevocation: $revocation,
                channel: $revocation->channel->value,
                purpose: $revocation->purpose->value,
                scope: $revocation->scope,
                data: [
                    'reason' => $validated['reason'],
                    'source' => $validated['source'] ?? null,
                    'ip_address' => $validated['ip_address'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                    'meta' => $revocation->meta,
                    'requested_scope' => $requestedScope,
                    'permission_boundary' => 'channel_purpose',
                ],
            );
        });
    }

    private function captureScopeFromConsent(?MessageConsent $consent): ?string
    {
        if (! $consent instanceof MessageConsent || ! is_string($consent->scope)) {
            return null;
        }

        $scope = $this->normalizeSegment($consent->scope);

        return $scope !== '' ? $scope : null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}