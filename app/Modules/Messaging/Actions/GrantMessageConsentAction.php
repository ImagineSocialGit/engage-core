<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Events\MessageConsentGranted;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Rules\MessageConsentRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GrantMessageConsentAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $optInPayload
     * @param array<string, mixed> $resolverContext
     *
     * @throws ValidationException
     */
    public function handle(
        Contact $contact,
        array $data,
        array $optInPayload = [],
        ?Model $context = null,
        array $resolverContext = [],
    ): MessageConsent {
        $validated = Validator::make($data, MessageConsentRules::rules())->validate();

        return DB::transaction(function () use ($contact, $validated, $optInPayload, $context, $resolverContext): MessageConsent {
            $channel = $validated['channel'];
            $purpose = $validated['purpose'];
            $scope = $validated['scope'];
            $consentedAt = $validated['consented_at'] ?? now();

            $wasActivelyConsented = $this->wasActivelyConsented(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            );

            $willBeActivelyConsented = $this->willBeActivelyConsented(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                consentedAt: $consentedAt,
            );

            $consent = MessageConsent::query()->updateOrCreate(
                [
                    'contact_id' => $contact->getKey(),
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'scope' => $scope,
                ],
                [
                    'consented_at' => $consentedAt,
                    'ip_address' => $validated['ip_address'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                    'source' => $validated['source'] ?? null,
                    'meta' => $validated['meta'] ?? null,
                ],
            );

            if (! $wasActivelyConsented && $willBeActivelyConsented) {
                DB::afterCommit(function () use ($contact, $consent, $channel, $purpose, $scope, $optInPayload, $context, $resolverContext, $validated): void {
                    MessageConsentGranted::dispatch(
                        contact: $contact,
                        messageConsent: $consent,
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                        context: $context,
                        data: [
                            'source' => $validated['source'] ?? null,
                            'ip_address' => $validated['ip_address'] ?? null,
                            'user_agent' => $validated['user_agent'] ?? null,
                            'meta' => $validated['meta'] ?? null,
                        ],
                    );

                    $this->dispatchMessageAction->handle(
                        recipient: $contact,
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                        dispatchKeys: 'consent_granted',
                        payload: $optInPayload,
                        context: $context,
                        meta: [
                            'resolver_context' => $resolverContext,
                        ],
                    );
                });
            }

            return $consent;
        });
    }

    private function wasActivelyConsented(
        Contact $contact,
        string $channel,
        string $purpose,
        string $scope,
    ): bool {
        $consent = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('scope', $scope)
            ->first();

        if (! $consent) {
            return false;
        }

        return ! ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('scope', $scope)
            ->where('revoked_at', '>=', $consent->consented_at)
            ->exists();
    }

    private function willBeActivelyConsented(
        Contact $contact,
        string $channel,
        string $purpose,
        string $scope,
        mixed $consentedAt,
    ): bool {
        return ! ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('scope', $scope)
            ->where('revoked_at', '>=', $consentedAt)
            ->exists();
    }
}