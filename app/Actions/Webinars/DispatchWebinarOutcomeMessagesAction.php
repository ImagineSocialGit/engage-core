<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Models\WebinarRegistration;
use App\Services\Messaging\MessageDefinitionResolver;

class DispatchWebinarOutcomeMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
    ) {}

    public function handle(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['contact', 'webinar']);

        if (! $registration->contact) {
            return;
        }

        $followUpType = $registration->attended_at ? 'replay' : 'missed';
        $payload = WebinarMessageData::fromRegistration($registration)->toArray();

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $channel,
                scope: self::SCOPE,
                message: 'follow_up',
                variant: $followUpType,
            );

            foreach ($definitions as $definition) {
                $this->dispatchMessageAction->handle(
                    contact: $registration->contact,
                    channel: $channel->value,
                    messageType: $definition['message_type'],
                    purpose: $definition['purpose'],
                    scope: $definition['scope'],
                    payloadClass: $definition['payload_class'],
                    payload: [
                        ...$payload,
                        'follow_up_type' => $followUpType,
                        'message_type' => $definition['message_type'],
                    ],
                    sendAt: now(),
                    context: $registration,
                    dedupeKey: implode(':', [
                        'scheduled-message',
                        $registration->contact->getKey(),
                        $registration->getMorphClass(),
                        $registration->getKey(),
                        $channel->value,
                        $definition['scope'],
                        $definition['message_type'],
                    ]),
                    meta: [
                        'queue' => $definition['queue'] ?? null,
                        'definition_config_path' => $definition['config_path'] ?? null,
                        'message' => $definition['message'] ?? null,
                        'variant' => $definition['variant'] ?? null,
                    ],
                );
            }
        }
    }
}