<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ConsentOptInDefinitionResolver;
use Illuminate\Database\Eloquent\Model;

class DispatchConsentOptInMessageAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly ConsentOptInDefinitionResolver $definitionResolver,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resolverContext
     * @return array<int, ScheduledMessage>
     */
    public function handle(
        Contact $contact,
        MessageConsentGrantResult $grant,
        array $payload = [],
        ?Model $context = null,
        array $resolverContext = [],
    ): array {
        if (! $grant->becameActive) {
            return [];
        }

        return $this->dispatchMessage->handle(
            recipient: $contact,
            channel: $grant->channel,
            purpose: $grant->purpose,
            scope: $grant->domain,
            dispatchKeys: 'consent_granted',
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
            payload: $payload,
            context: $context,
            meta: [
                'resolver_context' => $resolverContext,
                'consent' => [
                    'message_consent_id' => $grant->consent->getKey(),
                    'requested_scope' => $grant->requestedScope,
                    'domain' => $grant->domain,
                    'became_active' => true,
                ],
            ],
            definitions: [
                $this->definitionResolver->resolve(
                    channel: $grant->channel,
                    purpose: $grant->purpose,
                    messageScope: $grant->requestedScope,
                ),
            ],
        );
    }
}
