<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Services\ConsentOptInDefinitionResolver;
use Illuminate\Database\Eloquent\Model;

class BuildConsentOptInMessageIntentAction
{
    public function __construct(
        private readonly ConsentOptInDefinitionResolver $definitionResolver,
        private readonly EnsureConsentOptInMessageTemplateVersionAction $ensureTemplateVersion,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resolverContext
     */
    public function handle(
        Contact $contact,
        MessageConsentGrantResult $grant,
        array $payload = [],
        ?Model $context = null,
        array $resolverContext = [],
    ): ?MessageDeliveryIntent {
        if (! $grant->becameActive) {
            return null;
        }

        $definition = $this->definitionResolver->resolve(
            channel: $grant->channel,
            purpose: $grant->purpose,
            messageScope: $grant->requestedScope,
        );
        $version = $this->ensureTemplateVersion->handle($definition);
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && is_string($definition['dispatch_key'] ?? null)) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        $definition['dispatch_keys'] = is_array($dispatchKeys)
            ? $dispatchKeys
            : ['consent_granted'];
        $definition['message_template_version_id'] = $version->getKey();
        $definition['message_template_id'] = $version->message_template_id;
        $definition['message_template_key'] = $version->messageTemplate?->key;

        unset($definition['dispatch_key']);

        $intentKey = implode('.', [
            'consent',
            $this->normalizeSegment($grant->purpose),
            $this->normalizeSegment($grant->channel),
            'acknowledgement',
        ]);

        return MessageDeliveryIntent::fromDefinition(
            key: $intentKey,
            recipient: $contact,
            definition: $definition,
            payload: $payload,
            context: $context,
            triggeredAt: $grant->consent->consented_at,
            behavior: [
                'timing' => 'immediate',
            ],
            occurrenceKey: implode(':', [
                'consent_granted',
                $grant->consent->getKey(),
                $grant->channel,
                $grant->purpose,
                $grant->domain,
            ]),
            meta: [
                'resolver_context' => $resolverContext,
                'delivery_intent' => [
                    'key' => $intentKey,
                    'consent_ids' => [$grant->consent->getKey()],
                ],
                'consent' => [
                    'message_consent_id' => $grant->consent->getKey(),
                    'requested_scope' => $grant->requestedScope,
                    'domain' => $grant->domain,
                    'became_active' => true,
                ],
            ],
        );
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}