<?php

namespace App\Modules\Messaging\Services\Consent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;

class MessageConsentStateResolver
{
    /**
     * Scope is accepted for call-site compatibility and audit context only.
     * Permission state is resolved across the complete channel + purpose boundary.
     */
    public function latestConsent(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): ?MessageConsent {
        return MessageConsent::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Scope is accepted for call-site compatibility and audit context only.
     * Revocation state is resolved across the complete channel + purpose boundary.
     */
    public function latestRevocation(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): ?ConsentRevocation {
        return ConsentRevocation::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->orderByDesc('revoked_at')
            ->orderByDesc('id')
            ->first();
    }

    public function activeConsent(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): ?MessageConsent {
        $consent = $this->latestConsent($contact, $channel, $purpose);

        if (! $consent instanceof MessageConsent) {
            return null;
        }

        $revocation = $this->latestRevocation($contact, $channel, $purpose);

        if ($revocation && $revocation->revoked_at->greaterThanOrEqualTo($consent->consented_at)) {
            return null;
        }

        return $consent;
    }

    public function activeRevocation(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): ?ConsentRevocation {
        $revocation = $this->latestRevocation($contact, $channel, $purpose);

        if (! $revocation instanceof ConsentRevocation) {
            return null;
        }

        $consent = $this->latestConsent($contact, $channel, $purpose);

        if ($consent && $consent->consented_at->greaterThan($revocation->revoked_at)) {
            return null;
        }

        return $revocation;
    }

    public function isActive(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): bool {
        return $this->activeConsent($contact, $channel, $purpose) instanceof MessageConsent;
    }

    public function isRevoked(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        ?string $scope = null,
    ): bool {
        return $this->activeRevocation($contact, $channel, $purpose) instanceof ConsentRevocation;
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