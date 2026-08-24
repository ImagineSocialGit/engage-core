<?php

namespace App\Modules\Campaigns\Data;

use App\Modules\Campaigns\Models\CampaignEnrollment;

final class CampaignEligibilityLifecycleResult
{
    public const SKIPPED_INACTIVE = 'skipped_inactive';
    public const SKIPPED_MANUAL = 'skipped_manual';
    public const SKIPPED_INVALID_CONFIGURATION = 'skipped_invalid_configuration';
    public const NO_OPEN_ENROLLMENT = 'no_open_enrollment';
    public const EXISTING_OPEN_ENROLLMENT = 'existing_open_enrollment';
    public const ENROLLED = 'enrolled';
    public const RESUMED = 'resumed';
    public const PAUSED = 'paused';
    public const CANCELLED = 'cancelled';
    public const CONTINUED = 'continued';
    public const REENTRY_BLOCKED = 'reentry_blocked';
    public const FAMILY_BLOCKED = 'family_blocked';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $action,
        public readonly ?CampaignEligibilityEvaluationResult $evaluation = null,
        public readonly ?CampaignEnrollment $enrollment = null,
        public readonly array $meta = [],
    ) {}
}