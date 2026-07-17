<?php

namespace App\Modules\Webinars\Data;

use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;

final readonly class WebinarRegistrationConsentTransition
{
    public function __construct(
        public int $consentId,
        public string $channel,
        public string $purpose,
        public string $requestedScope,
        public string $domain,
        public bool $wasActive,
        public bool $isActive,
        public bool $created,
        public bool $becameActive,
    ) {}

    public static function fromGrant(MessageConsentGrantResult $grant): self
    {
        return new self(
            consentId: (int) $grant->consent->getKey(),
            channel: $grant->channel,
            purpose: $grant->purpose,
            requestedScope: $grant->requestedScope,
            domain: $grant->domain,
            wasActive: $grant->wasActive,
            isActive: $grant->isActive,
            created: $grant->created,
            becameActive: $grant->becameActive,
        );
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'consent_id' => $this->consentId,
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'requested_scope' => $this->requestedScope,
            'domain' => $this->domain,
            'was_active' => $this->wasActive,
            'is_active' => $this->isActive,
            'created' => $this->created,
            'became_active' => $this->becameActive,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            consentId: (int) ($data['consent_id'] ?? 0),
            channel: (string) ($data['channel'] ?? ''),
            purpose: (string) ($data['purpose'] ?? ''),
            requestedScope: (string) ($data['requested_scope'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            wasActive: (bool) ($data['was_active'] ?? false),
            isActive: (bool) ($data['is_active'] ?? false),
            created: (bool) ($data['created'] ?? false),
            becameActive: (bool) ($data['became_active'] ?? false),
        );
    }

    public function toGrant(): ?MessageConsentGrantResult
    {
        $consent = MessageConsent::query()->find($this->consentId);

        if (! $consent instanceof MessageConsent) {
            return null;
        }

        return new MessageConsentGrantResult(
            consent: $consent,
            channel: $this->channel,
            purpose: $this->purpose,
            requestedScope: $this->requestedScope,
            domain: $this->domain,
            wasActive: $this->wasActive,
            isActive: $this->isActive,
            created: $this->created,
            becameActive: $this->becameActive,
        );
    }
}
