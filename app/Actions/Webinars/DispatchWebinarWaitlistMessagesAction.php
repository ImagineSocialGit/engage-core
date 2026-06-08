<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Enums\MessageChannel;
use App\Models\Webinar;
use App\Models\WebinarWaitlistSignup;
use App\Services\Messaging\MessageDefinitionResolver;

class DispatchWebinarWaitlistMessagesAction
{
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
    ) {}

    public function handle(Webinar $webinar): void
    {
        $signups = WebinarWaitlistSignup::query()
            ->with(['contact', 'series'])
            ->where('webinar_series_id', $webinar->webinar_series_id)
            ->whereNull('notified_at')
            ->get();

        foreach ($signups as $signup) {
            $this->dispatchForSignup($signup);

            $signup->forceFill([
                'notified_at' => now(),
            ])->save();
        }
    }

    private function dispatchForSignup(WebinarWaitlistSignup $signup): void
    {
        if (! $signup->contact) {
            return;
        }

        $payload = [
            'contact_id' => $signup->contact->id,
            'contact_first_name' => $signup->contact->first_name ?? 'there',
            'contact_last_name' => $signup->contact->last_name,
            'contact_email' => $signup->contact->email,
            'contact_phone' => $signup->contact->phone,
            'webinar_series_id' => $signup->webinar_series_id,
            'webinar_series_title' => $signup->series?->title,
            'webinar_series_slug' => $signup->series?->slug,
            'source_page' => $signup->source_page,
        ];

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $channel,
                scope: self::SCOPE,
                message: 'scheduled',
            );

            foreach ($definitions as $definition) {
                $this->dispatchMessageAction->handle(
                    contact: $signup->contact,
                    channel: $channel->value,
                    messageType: $definition['message_type'],
                    purpose: $definition['purpose'],
                    scope: $definition['scope'],
                    payloadClass: $definition['payload_class'],
                    payload: [
                        ...$payload,
                        'message_type' => $definition['message_type'],
                    ],
                    sendAt: now(),
                    context: $signup,
                    dedupeKey: implode(':', [
                        'scheduled-message',
                        $signup->contact->getKey(),
                        $signup->getMorphClass(),
                        $signup->getKey(),
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