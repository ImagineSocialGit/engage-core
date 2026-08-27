<?php

namespace App\Modules\Scheduling\Data;

use Carbon\CarbonImmutable;

final readonly class DestinationVerificationProof
{
    public function __construct(
        public string $token,
        public string $channel,
        public string $destination,
        public CarbonImmutable $verifiedAt,
        public CarbonImmutable $expiresAt,
    ) {}
}