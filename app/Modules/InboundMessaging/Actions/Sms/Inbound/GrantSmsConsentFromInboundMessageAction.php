<?php

namespace App\Modules\InboundMessaging\Actions\Sms\Inbound;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Contracts\InboundMessageHandler;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use BackedEnum;

class GrantSmsConsentFromInboundMessageAction implements InboundMessageHandler
{
    public function __construct(
        private readonly GrantMessageConsentAction $grantMessageConsent,
        private readonly MessageConsentStateResolver $consentState,
    ) {}

    public function handle(InboundMessage $inboundMessage): ?string
    {
        $sender = $inboundMessage->sender;

        if (! $sender instanceof Contact) {
            return $this->notRestoredResponse();
        }

        $purpose = $this->value($inboundMessage->purpose);
        $restored = 0;

        foreach ($this->historicalPurposes($sender, $purpose) as $historicalPurpose) {
            $consent = $this->restorableConsent($sender, $historicalPurpose);

            if (! $consent instanceof MessageConsent) {
                continue;
            }

            $result = $this->grantMessageConsent->handle(
                contact: $sender,
                data: [
                    'channel' => MessageChannel::Sms->value,
                    'purpose' => $historicalPurpose,
                    'scope' => $consent->scope,
                    'source' => $this->source($inboundMessage),
                    'consented_at' => $inboundMessage->received_at ?? now(),
                    'meta' => [
                        'reopt_in' => [
                            'reason_context' => 'inbound_start_keyword',
                            'inbound_message_id' => $inboundMessage->getKey(),
                            'restored_from_consent_id' => (int) $consent->getKey(),
                        ],
                    ],
                ],
                context: $inboundMessage,
            );

            if ($result->becameActive) {
                $restored++;
            }
        }

        $inboundMessage->markProcessed();

        return $restored > 0 || $this->hasActiveSmsConsent($sender, $purpose)
            ? $this->startResponse()
            : $this->notRestoredResponse();
    }

    /**
     * @return array<int, string>
     */
    private function historicalPurposes(Contact $contact, ?string $purpose): array
    {
        return MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', MessageChannel::Sms->value)
            ->when(
                $purpose !== null,
                fn ($query) => $query->where('purpose', $purpose),
            )
            ->pluck('purpose')
            ->map(fn (mixed $value): ?string => $this->value($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function restorableConsent(
        Contact $contact,
        string $purpose,
    ): ?MessageConsent {
        if ($this->consentState->isActive(
            contact: $contact,
            channel: MessageChannel::Sms,
            purpose: $purpose,
        )) {
            return null;
        }

        $consent = $this->consentState->latestConsent(
            contact: $contact,
            channel: MessageChannel::Sms,
            purpose: $purpose,
        );
        $revocation = $this->consentState->latestRevocation(
            contact: $contact,
            channel: MessageChannel::Sms,
            purpose: $purpose,
        );

        if (! $consent instanceof MessageConsent
            || ! $revocation instanceof ConsentRevocation
            || $revocation->reason !== ConsentRevocation::REASON_STOP
            || $revocation->revoked_at->lessThan($consent->consented_at)
        ) {
            return null;
        }

        return $consent;
    }

    private function hasActiveSmsConsent(
        Contact $contact,
        ?string $purpose,
    ): bool {
        foreach ($this->historicalPurposes($contact, $purpose) as $historicalPurpose) {
            if ($this->consentState->isActive(
                contact: $contact,
                channel: MessageChannel::Sms,
                purpose: $historicalPurpose,
            )) {
                return true;
            }
        }

        return false;
    }

    private function source(InboundMessage $inboundMessage): string
    {
        $provider = trim((string) $inboundMessage->provider);

        return $provider !== ''
            ? $provider.'_inbound_sms_start'
            : 'inbound_sms_start';
    }

    private function startResponse(): ?string
    {
        return config('messaging.sms.inbound.start_response');
    }

    private function notRestoredResponse(): ?string
    {
        return config('messaging.sms.inbound.start_no_prior_consent_response');
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof MessagePurpose) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}