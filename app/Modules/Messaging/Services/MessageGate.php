<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;

class MessageGate
{
    public function __construct(
        private readonly MessageSuppressionService $messageSuppressionService,
        private readonly ContactPermissionInvitationService $permissionInvitationService,
        private readonly ConsentDomainRegistry $consentDomainRegistry,
        private readonly MessageConsentStateResolver $consentStateResolver,
    ) {}

    /**
     * @param array<string, mixed>|null $context
     */
    public function allows(
        Contact $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
        ?string $messageKey = null,
        ?array $context = null,
    ): bool {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizePurpose($purpose);
        $scope = $this->normalizeNullableSegment($scope) ?? '';
        $messageKey = $this->normalizeNullableSegment($messageKey);
        $context ??= [];

        if ($scope === '') {
            return false;
        }

        $destination = $this->destinationFor($contact, $channel);

        if (! $destination) {
            return false;
        }

        $consentDomain = $this->consentDomainRegistry->domainForScope($scope);

        if (
            ! $this->consentStateResolver->isActive($contact, $channel, $purpose, $consentDomain)
            && ! $this->allowsImportedContactInvitationPass($contact, $channel, $messageKey, $context)
        ) {
            return false;
        }

        return ! $this->messageSuppressionService->isSuppressed($channel, $destination);
    }

    public function canSend(
        Contact $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): bool {
        return $this->allows($contact, $channel, $purpose, $scope);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function allowsImportedContactInvitationPass(
        Contact $contact,
        string $channel,
        ?string $messageKey,
        array $context,
    ): bool {
        if (! $messageKey) {
            return false;
        }

        if (! $this->permissionInvitationService->allowsImportedContactInvitationPass(
            contact: $contact,
            channel: $channel,
            messageType: $messageKey,
            context: $context,
        )) {
            return false;
        }

        return ! ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', $channel)
            ->exists();
    }

    private function destinationFor(Contact $contact, string $channel): ?string
    {
        return match ($channel) {
            MessageChannel::Sms->value => $contact->phone ?? null,
            MessageChannel::Email->value => $contact->email ?? null,
            default => null,
        };
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }

    private function normalizePurpose(MessagePurpose|string $purpose): string
    {
        return $purpose instanceof MessagePurpose
            ? $purpose->value
            : strtolower(trim($purpose));
    }

    private function normalizeNullableSegment(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }
}