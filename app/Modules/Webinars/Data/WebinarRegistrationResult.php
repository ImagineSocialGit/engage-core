<?php

namespace App\Modules\Webinars\Data;

use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Webinars\Models\WebinarRegistration;

final readonly class WebinarRegistrationResult
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_EXISTING = 'existing';

    /**
     * @param array<int, MessageConsentGrantResult> $consentGrants
     */
    public function __construct(
        public WebinarRegistration $registration,
        public string $outcome,
        public array $consentGrants = [],
    ) {}

    /** @param array<int, MessageConsentGrantResult> $consentGrants */
    public static function created(
        WebinarRegistration $registration,
        array $consentGrants = [],
    ): self {
        return new self($registration, self::OUTCOME_CREATED, $consentGrants);
    }

    /** @param array<int, MessageConsentGrantResult> $consentGrants */
    public static function existing(
        WebinarRegistration $registration,
        array $consentGrants = [],
    ): self {
        return new self($registration, self::OUTCOME_EXISTING, $consentGrants);
    }

    public function wasCreated(): bool
    {
        return $this->outcome === self::OUTCOME_CREATED;
    }

    public function wasExisting(): bool
    {
        return $this->outcome === self::OUTCOME_EXISTING;
    }

    /** @return array<int, array<string, int|string|bool>> */
    public function consentTransitionPayloads(): array
    {
        return array_values(array_map(
            static fn (MessageConsentGrantResult $grant): array =>
                WebinarRegistrationConsentTransition::fromGrant($grant)->toArray(),
            $this->consentGrants,
        ));
    }
}
