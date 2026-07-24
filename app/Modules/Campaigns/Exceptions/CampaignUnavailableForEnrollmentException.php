<?php

namespace App\Modules\Campaigns\Exceptions;

use InvalidArgumentException;

class CampaignUnavailableForEnrollmentException extends InvalidArgumentException
{
    public const REASON_MISSING = 'campaign_missing';
    public const REASON_INACTIVE = 'campaign_inactive';

    public function __construct(
        public readonly string $campaignKey,
        public readonly string $reason,
        public readonly ?string $campaignStatus = null,
    ) {
        parent::__construct(match ($reason) {
            self::REASON_MISSING => "Campaign [{$campaignKey}] was not found.",
            self::REASON_INACTIVE => "Campaign [{$campaignKey}] is not active.",
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
}