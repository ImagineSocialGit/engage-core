<?php

namespace App\Modules\Messaging\Services\Consent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\ConsentDomainRegistry;

class MessageConsentStateResolver
{
    public function __construct(
        private readonly ConsentDomainRegistry $consentDomainRegistry,
    ) {}

    public function latestConsent(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): ?MessageConsent {
        $canonicalScope = $this->consentDomainRegistry->domainFor(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
        );

        return MessageConsent::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->where('scope', $canonicalScope)
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestRevocation(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): ?ConsentRevocation {
        $canonicalScope = $this->consentDomainRegistry->domainFor(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
        );

        return ConsentRevocation::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->where('scope', $canonicalScope)
            ->orderByDesc('revoked_at')
            ->orderByDesc('id')
            ->first();
    }

    public function activeConsent(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): ?MessageConsent {
        $consent = $this->latestConsent($contact, $channel, $purpose, $scope);

        if (! $consent instanceof MessageConsent) {
            return null;
        }

        $revocation = $this->latestRevocation($contact, $channel, $purpose, $scope);

        if ($revocation && $revocation->revoked_at->greaterThanOrEqualTo($consent->consented_at)) {
            return null;
        }

        return $consent;
    }

    public function isActive(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): bool {
        return $this->activeConsent($contact, $channel, $purpose, $scope) instanceof MessageConsent;
    }

    private function contactId(Contact|int $contact): int
    {
        return $contact instanceof Contact
            ? (int) $contact->getKey()
            : $contact;
    }

    private function enumValue(MessageChannel|MessagePurpose|string $value): string
    {
        return $value instanceof MessageChannel || $value instanceof MessagePurpose
            ? $value->value
            : $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}