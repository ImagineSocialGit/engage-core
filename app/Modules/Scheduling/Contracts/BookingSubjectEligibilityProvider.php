<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Data\BookingSubjectEligibilityContext;

interface BookingSubjectEligibilityProvider
{
    public function key(): string;

    /** @param array<string, mixed> $policy */
    public function validatePolicy(array $policy): void;

    public function assertEligible(BookingSubjectEligibilityContext $context): void;
}