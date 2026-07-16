<?php

namespace App\Modules\Messaging\Services\Consent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;

class MessageConsentStateResolver
{
    public function latestConsent(
        Contact|int $contact,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
    ): ?MessageConsent {
        return MessageConsent::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->where('scope', $this->normalizeSegment($scope))
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
        return ConsentRevocation::query()
            ->where('contact_id', $this->contactId($contact))
            ->where('channel', $this->enumValue($channel))
            ->where('purpose', $this->enumValue($purpose))
            ->where('scope', $this->normalizeSegment($scope))
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
