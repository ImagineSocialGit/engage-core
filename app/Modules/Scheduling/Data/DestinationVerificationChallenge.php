<?php

namespace App\Modules\Scheduling\Data;

use Carbon\CarbonImmutable;

final readonly class DestinationVerificationChallenge
{
    public function __construct(
        public string $challengeId,
        public string $channel,
        public string $destination,
        public string $maskedDestination,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $resendAvailableAt,
    ) {}
}