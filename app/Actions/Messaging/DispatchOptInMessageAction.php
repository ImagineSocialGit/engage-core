<?php

namespace App\Actions\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Contact;
use App\Services\Messaging\MessageDefinitionResolver;
use Illuminate\Database\Eloquent\Model;

class DispatchOptInMessageAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $resolverContext
     */
    public function handle(
        Contact $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
        array $payload = [],
        ?Model $context = null,
        array $resolverContext = [],
    ): void {
        $channelValue = $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));

        $purposeValue = $purpose instanceof MessagePurpose
            ? $purpose->value
            : strtolower(trim($purpose));

        $definitions = $this->messageDefinitionResolver->resolve(
            channel: $channelValue,
            scope: $scope,
            message: 'opt_in',
            context: $resolverContext,
        );

        foreach ($definitions as $definition) {
            if (($definition['purpose'] ?? null) !== $purposeValue) {
                continue;
            }

            $this->dispatchMessageAction->handle(
                contact: $contact,
                channel: $channelValue,
                messageType: $definition['message_type'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
                payloadClass: $definition['payload_class'],
                payload: array_replace_recursive(
                    $definition['payload'] ?? [],
                    $payload,
                    [
                        'contact_id' => $contact->id,
                        'contact_first_name' => $contact->first_name ?? 'there',
                        'contact_last_name' => $contact->last_name,
                        'contact_email' => $contact->email,
                        'contact_phone' => $contact->phone,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'message_type' => $definition['message_type'],
                        'scope' => $definition['scope'],
                        'purpose' => $definition['purpose'],
                    ],
                ),
                sendAt: now(),
                context: $context,
                dedupeKey: implode(':', [
                    'opt-in',
                    $contact->getKey(),
                    $channelValue,
                    $purposeValue,
                    $definition['scope'],
                ]),
                meta: [
                    'queue' => $definition['queue'] ?? null,
                    'definition_config_path' => $definition['config_path'] ?? null,
                    'override_config_path' => $definition['override_config_path'] ?? null,
                    'message' => $definition['message'] ?? null,
                    'variant' => $definition['variant'] ?? null,
                ],
            );
        }
    }
}