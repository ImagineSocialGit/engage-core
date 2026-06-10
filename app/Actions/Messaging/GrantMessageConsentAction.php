<?php

namespace App\Actions\Messaging;

use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\MessageConsent;
use App\Rules\Messaging\MessageConsentRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GrantMessageConsentAction
{
    public function __construct(
        private readonly DispatchOptInMessageAction $dispatchOptInMessageAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $optInPayload
     * @param  array<string, mixed>  $resolverContext
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
            ]
        );

        if (! $wasActivelyConsented && $willBeActivelyConsented) {
            DB::afterCommit(function () use ($contact, $channel, $purpose, $scope, $optInPayload, $context, $resolverContext): void {
                $this->dispatchOptInMessageAction->handle(
                    contact: $contact,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    payload: $optInPayload,
                    context: $context,
                    resolverContext: $resolverContext,
                );
            });
        }

        return $consent;
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