<?php

namespace App\Support\HumanVerification\Data;

use DateTimeImmutable;

final readonly class HumanVerificationResult
{
    public const REASON_CONFIGURATION = 'configuration';
    public const REASON_INVALID_TOKEN = 'invalid_token';
    public const REASON_EXPIRED_OR_DUPLICATE = 'expired_or_duplicate';
    public const REASON_HOSTNAME_MISMATCH = 'hostname_mismatch';
    public const REASON_ACTION_MISMATCH = 'action_mismatch';
    public const REASON_STALE_CHALLENGE = 'stale_challenge';
    public const REASON_INVALID_RESPONSE = 'invalid_response';
    public const REASON_UNAVAILABLE = 'unavailable';

    public function __construct(
        public bool $required,
        public bool $verified,
        public ?string $provider = null,
        public ?DateTimeImmutable $verifiedAt = null,
        public ?DateTimeImmutable $challengeAt = null,
        public ?string $hostname = null,
        public ?string $action = null,
        public ?string $reason = null,
    ) {}

    public static function notRequired(): self
    {
        return new self(
            required: false,
            verified: true,
        );
    }

    public static function failed(
        string $provider,
        string $reason,
        ?DateTimeImmutable $challengeAt = null,
        ?string $hostname = null,
        ?string $action = null,
    ): self {
        return new self(
            required: true,
            verified: false,
            provider: $provider,
            challengeAt: $challengeAt,
            hostname: $hostname,
            action: $action,
            reason: $reason,
        );
    }

    public static function verified(
        string $provider,
        DateTimeImmutable $verifiedAt,
        DateTimeImmutable $challengeAt,
        string $hostname,
        string $action,
    ): self {
        return new self(
            required: true,
            verified: true,
            provider: $provider,
            verifiedAt: $verifiedAt,
            challengeAt: $challengeAt,
            hostname: $hostname,
            action: $action,
        );
    }

    public function passes(): bool
    {
        return ! $this->required || $this->verified;
    }
}