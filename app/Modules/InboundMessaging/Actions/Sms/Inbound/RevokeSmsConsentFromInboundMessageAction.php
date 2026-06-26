<?php

namespace App\Modules\InboundMessaging\Actions\Sms\Inbound;

use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\InboundMessaging\Contracts\InboundMessageHandler;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use BackedEnum;

class RevokeSmsConsentFromInboundMessageAction implements InboundMessageHandler
{
    public function __construct(
        private readonly RevokeMessageConsentAction $revokeMessageConsentAction,
    ) {}

    public function handle(InboundMessage $inboundMessage): ?string
    {
        $sender = $inboundMessage->sender;

        if (! $sender instanceof Contact) {
            return $this->stopResponse($inboundMessage);
        }

        $purpose = $this->value($inboundMessage->purpose);

        if ($purpose !== null) {
            $this->revokePurpose(
                inboundMessage: $inboundMessage,
                contact: $sender,
                purpose: $purpose,
                scope: null,
            );

            $inboundMessage->markProcessed();

            return $this->stopResponse($inboundMessage);
        }

        $this->logUnknownProviderContext($inboundMessage, $sender);
        $this->revokeAllSmsConsent($inboundMessage, $sender);

        $inboundMessage->markProcessed();

        return $this->stopResponse($inboundMessage);
    }

    private function revokeAllSmsConsent(InboundMessage $inboundMessage, Contact $contact): void
    {
        $contact
            ->messageConsents()
            ->where('channel', MessageChannel::Sms->value)
            ->get(['purpose', 'scope'])
            ->map(fn ($consent) => [
                'purpose' => $this->value($consent->purpose),
                'scope' => $consent->scope,
            ])
            ->filter(fn (array $consent) => $consent['purpose'] !== null)
            ->unique(fn (array $consent) => $consent['purpose'].'|'.$consent['scope'])
            ->values()
            ->each(function (array $consent) use ($inboundMessage, $contact): void {
                $this->revokePurpose(
                    inboundMessage: $inboundMessage,
                    contact: $contact,
                    purpose: $consent['purpose'],
                    scope: $consent['scope'],
                );
            });
    }

    private function revokePurpose(
        InboundMessage $inboundMessage,
        Contact $contact,
        string $purpose,
        ?string $scope,
    ): void {
        $this->revokeMessageConsentAction->handle($contact, [
            'channel' => MessageChannel::Sms->value,
            'purpose' => $purpose,
            'scope' => $scope,
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => $this->source($inboundMessage),
            'ip_address' => data_get($inboundMessage->meta, 'ip_address'),
            'user_agent' => data_get($inboundMessage->meta, 'user_agent'),
            'meta' => $this->revocationMeta($inboundMessage),
        ]);
    }

    private function revocationMeta(InboundMessage $inboundMessage): array
    {
        return [
            'reason_context' => 'inbound_stop_keyword',
            'inbound_message_id' => $inboundMessage->id,
            'provider' => $inboundMessage->provider,
            'provider_message_id' => $inboundMessage->provider_message_id,
            'provider_event_id' => $inboundMessage->provider_event_id,
            'provider_context_id' => $inboundMessage->provider_context_id,
            'keyword' => $this->normalizedBody($inboundMessage),
            'raw_body' => $inboundMessage->body,
        ];
    }

    private function source(InboundMessage $inboundMessage): string
    {
        $source = data_get($inboundMessage->meta, 'source');

        return is_string($source) && trim($source) !== ''
            ? $source
            : $inboundMessage->provider.'_inbound_sms';
    }

    private function stopResponse(InboundMessage $inboundMessage): ?string
    {
        return config('messaging.sms.inbound.stop_response');
    }

    private function normalizedBody(InboundMessage $inboundMessage): ?string
    {
        if (! is_string($inboundMessage->body)) {
            return null;
        }

        $body = strtoupper(trim($inboundMessage->body));

        return $body === '' ? null : $body;
    }

    private function logUnknownProviderContext(InboundMessage $inboundMessage, Contact $contact): void
    {
        logger()->warning('Unknown inbound SMS provider context ID; revoking all SMS consent for contact.', [
            'contact_id' => $contact->id,
            'inbound_message_id' => $inboundMessage->id,
            'provider' => $inboundMessage->provider,
            'provider_context_id' => $inboundMessage->provider_context_id,
            'provider_message_id' => $inboundMessage->provider_message_id,
            'provider_event_id' => $inboundMessage->provider_event_id,
        ]);
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof MessagePurpose) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}