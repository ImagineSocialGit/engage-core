<?php

namespace App\Modules\Messaging\Data\Delivery;

final readonly class SmsSendDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reasonCode = null,
        public ?string $reason = null,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function denied(string $reasonCode, string $reason): self
    {
        return new self(
            allowed: false,
            reasonCode: $reasonCode,
            reason: $reason,
        );
    }
}
