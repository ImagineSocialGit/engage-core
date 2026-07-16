<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Rules\MessageConsentRules;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordMessageConsentAction
{
    public function __construct(
        private readonly ConsentDomainRegistry $consentDomainRegistry,
        private readonly MessageConsentStateResolver $stateResolver,
    ) {}

    /**
     * Persist a consent transition without emitting events or dispatching messages.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public function handle(Contact $contact, array $data): MessageConsentGrantResult
    {
        $validated = Validator::make($data, MessageConsentRules::rules())->validate();

        $channel = $this->normalizeSegment($validated['channel']);
        $purpose = $this->normalizeSegment($validated['purpose']);
        $requestedScope = $this->normalizeSegment($validated['scope']);
        $domain = $this->consentDomainRegistry->domainForScope($requestedScope);
        $consentedAt = isset($validated['consented_at'])
            ? Carbon::parse($validated['consented_at'])
            : now();

        return DB::transaction(function () use (
            $contact,
            $validated,
            $channel,
            $purpose,
            $requestedScope,
            $domain,
            $consentedAt,
        ): MessageConsentGrantResult {
            Contact::query()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $activeConsent = $this->stateResolver->activeConsent(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $domain,
            );

            if ($activeConsent instanceof MessageConsent) {
                return new MessageConsentGrantResult(
                    consent: $activeConsent,
                    channel: $channel,
                    purpose: $purpose,
                    requestedScope: $requestedScope,
                    domain: $domain,
                    wasActive: true,
                    isActive: true,
                    created: false,
                    becameActive: false,
                );
            }

            $existingHistoricalConsent = MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', $channel)
                ->where('purpose', $purpose)
                ->where('scope', $domain)
                ->where('consented_at', $consentedAt)
                ->when(
                    isset($validated['source']),
                    fn ($query) => $query->where('source', $validated['source']),
                    fn ($query) => $query->whereNull('source'),
                )
                ->orderByDesc('id')
                ->first();

            $created = false;
            $consent = $existingHistoricalConsent;

            if (! $consent instanceof MessageConsent) {
                $consent = MessageConsent::query()->create([
                    'contact_id' => $contact->getKey(),
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'scope' => $domain,
                    'consented_at' => $consentedAt,
                    'ip_address' => $validated['ip_address'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                    'source' => $validated['source'] ?? null,
                    'meta' => array_replace_recursive(
                        is_array($validated['meta'] ?? null) ? $validated['meta'] : [],
                        [
                            'consent' => [
                                'requested_scope' => $requestedScope,
                                'domain' => $domain,
                            ],
                        ],
                    ),
                ]);

                $created = true;
            }

            $isActive = $this->stateResolver->isActive(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $domain,
            );

            return new MessageConsentGrantResult(
                consent: $consent,
                channel: $channel,
                purpose: $purpose,
                requestedScope: $requestedScope,
                domain: $domain,
                wasActive: false,
                isActive: $isActive,
                created: $created,
                becameActive: $isActive,
            );
        });
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
