<?php

namespace App\Modules\Campaigns\Data;

use App\Modules\Campaigns\Models\CampaignEligibilityState;

final class CampaignEligibilityEvaluationResult
{
    public const BECAME_ELIGIBLE = 'became_eligible';
    public const BECAME_INELIGIBLE = 'became_ineligible';
    public const UNCHANGED_ELIGIBLE = 'unchanged_eligible';
    public const UNCHANGED_INELIGIBLE = 'unchanged_ineligible';

    public function __construct(
        public readonly CampaignEligibilityState $state,
        public readonly bool $previousEligible,
        public readonly bool $currentEligible,
        public readonly string $transition,
        public readonly int $eligibilityCycle,
    ) {}

    public function becameEligible(): bool
    {
        return $this->transition === self::BECAME_ELIGIBLE;
    }

    public function becameIneligible(): bool
    {
        return $this->transition === self::BECAME_INELIGIBLE;
    }
}