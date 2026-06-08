<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Models\WebinarRegistration;
use App\Services\Messaging\MessageDefinitionResolver;
use Carbon\CarbonInterface;

class DispatchWebinarRegistrationMessagesAction
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

        $payload = WebinarMessageData::fromRegistration($registration)->toArray();

        $this->dispatchImmediateMessages($registration, $payload);

        if (! $registration->webinar) {
            return;
        }

        $this->dispatchReminderMessages($registration, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchImmediateMessages(WebinarRegistration $registration, array $payload): void
    {
        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $this->dispatchDefinitions(
                registration: $registration,
                channel: $channel,
                definitions: $this->messageDefinitionResolver->resolve(
                    channel: $channel,
                    scope: self::SCOPE,
                    message: 'registration_confirmation',
                ),
                payload: $payload,
                sendAt: now(),
            );

            if ($channel === MessageChannel::Sms) {
                $this->dispatchSmsOptInMessages(
                    registration: $registration,
                    payload: $payload,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchReminderMessages(WebinarRegistration $registration, array $payload): void
    {
        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $channel,
                scope: self::SCOPE,
                message: 'reminders',
            );

            foreach ($definitions as $definition) {
                $offsetMinutes = $definition['offset_minutes_before_start'] ?? null;

                if (! is_numeric($offsetMinutes)) {
                    continue;
                }

                $sendAt = $registration->webinar->starts_at
                    ->copy()
                    ->subMinutes((int) $offsetMinutes);

                if ($sendAt->isPast()) {
                    continue;
                }

                $reminderPayload = [
                    ...$payload,
                    'message_type' => $definition['message_type'],
                    'reminder_type' => $definition['variant'] ?? $definition['message_type'],
                ];

                $this->dispatchDefinition(
                    registration: $registration,
                    channel: $channel,
                    definition: $definition,
                    payload: $reminderPayload,
                    sendAt: $sendAt,
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $payload
     */
    private function dispatchDefinitions(
        WebinarRegistration $registration,
        MessageChannel $channel,
        array $definitions,
        array $payload,
        CarbonInterface $sendAt,
    ): void {
        foreach ($definitions as $definition) {
            $this->dispatchDefinition(
                registration: $registration,
                channel: $channel,
                definition: $definition,
                payload: [
                    ...$payload,
                    'message_type' => $definition['message_type'],
                ],
                sendAt: $sendAt,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $payload
     */
    private function dispatchDefinition(
        WebinarRegistration $registration,
        MessageChannel $channel,
        array $definition,
        array $payload,
        CarbonInterface $sendAt,
    ): void {
        $this->dispatchMessageAction->handle(
            contact: $registration->contact,
            channel: $channel->value,
            messageType: $definition['message_type'],
            purpose: $definition['purpose'],
            scope: $definition['scope'],
            payloadClass: $definition['payload_class'],
            payload: $payload,
            sendAt: $sendAt,
            context: $registration,
            dedupeKey: $this->dedupeKey(
                registration: $registration,
                channel: $channel->value,
                scope: $definition['scope'],
                messageType: $definition['message_type'],
            ),
            meta: [
                'queue' => $definition['queue'] ?? null,
                'definition_config_path' => $definition['config_path'] ?? null,
                'message' => $definition['message'] ?? null,
                'variant' => $definition['variant'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSmsOptInMessages(WebinarRegistration $registration, array $payload): void
    {
        $definitions = $this->messageDefinitionResolver->resolve(
            channel: MessageChannel::Sms,
            scope: self::SCOPE,
            message: 'transactional_opt_in',
        );

        foreach ($definitions as $definition) {
            $this->dispatchMessageAction->handle(
                contact: $registration->contact,
                channel: MessageChannel::Sms->value,
                messageType: $definition['message_type'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
                payloadClass: $definition['payload_class'],
                payload: [
                    ...$payload,
                    'message_type' => $definition['message_type'],
                ],
                sendAt: now(),
                context: $registration,
                dedupeKey: implode(':', [
                    'sms-opt-in',
                    $registration->contact->getKey(),
                    $definition['purpose'],
                    $definition['scope'],
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

    private function dedupeKey(
        WebinarRegistration $registration,
        string $channel,
        string $scope,
        string $messageType,
    ): string {
        return implode(':', [
            'scheduled-message',
            $registration->contact->getKey(),
            $registration->getMorphClass(),
            $registration->getKey(),
            $channel,
            $scope,
            $messageType,
        ]);
    }
}