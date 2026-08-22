<?php

namespace App\Modules\Campaigns\Data\Automation;

class ResumeCampaignAutomationDefinition
{
    public const ON_NOT_ENROLLED_SKIPPED = 'skipped';
    public const ON_NOT_ENROLLED_COMPLETED = 'completed';
    public const ON_NOT_ENROLLED_BLOCKED = 'blocked';
    public const ON_NOT_ENROLLED_FAILED = 'failed';

    public const ON_NOT_ENROLLED_OPTIONS = [
        self::ON_NOT_ENROLLED_SKIPPED,
        self::ON_NOT_ENROLLED_COMPLETED,
        self::ON_NOT_ENROLLED_BLOCKED,
        self::ON_NOT_ENROLLED_FAILED,
    ];

    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly ?string $campaignKey,
        public readonly string $reason = 'flow_route_resumed_campaign',
        public readonly string $onNotEnrolled = self::ON_NOT_ENROLLED_SKIPPED,
        public readonly array $meta = [],
        public readonly ?string $invalidReason = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $campaignKey = self::nullableString($input['campaign_key'] ?? null);
        $onNotEnrolled = self::nullableString($input['on_not_enrolled'] ?? null)
            ?? self::ON_NOT_ENROLLED_SKIPPED;

        return new self(
            campaignKey: $campaignKey,
            reason: self::nullableString($input['reason'] ?? null) ?? 'flow_route_resumed_campaign',
            onNotEnrolled: $onNotEnrolled,
            meta: is_array($input['meta'] ?? null) ? $input['meta'] : [],
            invalidReason: match (true) {
                $campaignKey === null => 'resume_campaign_missing_campaign_key',
                ! in_array($onNotEnrolled, self::ON_NOT_ENROLLED_OPTIONS, true) => 'resume_campaign_invalid_on_not_enrolled',
                default => null,
            },
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    /** @return array<string, mixed> */
    public function toMetaPayload(): array
    {
        return [
            'campaign_key' => $this->campaignKey,
            'reason' => $this->reason,
            'on_not_enrolled' => $this->onNotEnrolled,
            'meta' => $this->meta,
            'invalid_reason' => $this->invalidReason,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}