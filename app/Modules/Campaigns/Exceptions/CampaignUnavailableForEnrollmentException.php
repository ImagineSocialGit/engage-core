<?php

namespace App\Modules\Campaigns\Exceptions;

use InvalidArgumentException;

class CampaignUnavailableForEnrollmentException extends InvalidArgumentException
{
    public const REASON_MISSING = 'campaign_missing';
    public const REASON_INACTIVE = 'campaign_inactive';
    public const REASON_FAMILY_BLOCKED = 'campaign_family_blocked';

    public function __construct(
        public readonly string $campaignKey,
        public readonly string $reason,
        public readonly ?string $campaignStatus = null,
        public readonly ?string $familyKey = null,
        public readonly ?int $campaignPriority = null,
        public readonly ?string $blockingCampaignKey = null,
        public readonly ?int $blockingPriority = null,
        public readonly ?int $blockingEnrollmentId = null,
    ) {
        parent::__construct(match ($reason) {
            self::REASON_MISSING => "Campaign [{$campaignKey}] was not found.",
            self::REASON_INACTIVE => "Campaign [{$campaignKey}] is not active.",
            self::REASON_FAMILY_BLOCKED => sprintf(
                'Campaign [%s] cannot enroll because family [%s] already has open Campaign [%s] at priority [%d], which is not lower than candidate priority [%d].',
                $campaignKey,
                $familyKey,
                $blockingCampaignKey,
                $blockingPriority,
                $campaignPriority,
            ),
            default => "Campaign [{$campaignKey}] is unavailable for enrollment.",
        });
    }

    public static function missing(string $campaignKey): self
    {
        return new self(
            campaignKey: $campaignKey,
            reason: self::REASON_MISSING,
        );
    }

    public static function inactive(string $campaignKey, ?string $campaignStatus): self
    {
        return new self(
            campaignKey: $campaignKey,
            reason: self::REASON_INACTIVE,
            campaignStatus: $campaignStatus,
        );
    }

    public static function familyBlocked(
        string $campaignKey,
        string $familyKey,
        int $campaignPriority,
        string $blockingCampaignKey,
        int $blockingPriority,
        int $blockingEnrollmentId,
    ): self {
        return new self(
            campaignKey: $campaignKey,
            reason: self::REASON_FAMILY_BLOCKED,
            familyKey: $familyKey,
            campaignPriority: $campaignPriority,
            blockingCampaignKey: $blockingCampaignKey,
            blockingPriority: $blockingPriority,
            blockingEnrollmentId: $blockingEnrollmentId,
        );
    }
}