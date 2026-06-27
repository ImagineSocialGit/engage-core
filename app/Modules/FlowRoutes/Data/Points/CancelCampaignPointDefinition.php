<?php

namespace App\Modules\FlowRoutes\Data\Points;

class CancelCampaignPointDefinition
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

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $campaignKey,
        public readonly string $reason = 'flow_route_cancelled_campaign',
        public readonly string $onNotEnrolled = self::ON_NOT_ENROLLED_SKIPPED,
        public readonly bool $skipPendingMessages = true,
        public readonly array $meta = [],
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $values = array_replace_recursive($definition, $settings);

        $campaignKey = self::nullableString($values['campaign_key'] ?? null);

        $reason = self::nullableString($values['reason'] ?? null)
            ?? 'flow_route_cancelled_campaign';

        $onNotEnrolled = self::nullableString($values['on_not_enrolled'] ?? null)
            ?? self::ON_NOT_ENROLLED_SKIPPED;

        $skipPendingMessages = (bool) ($values['skip_pending_messages'] ?? true);

        $meta = is_array($values['meta'] ?? null)
            ? $values['meta']
            : [];

        return new self(
            campaignKey: $campaignKey,
            reason: $reason,
            onNotEnrolled: $onNotEnrolled,
            skipPendingMessages: $skipPendingMessages,
            meta: $meta,
            invalidReason: self::invalidReason(
                campaignKey: $campaignKey,
                onNotEnrolled: $onNotEnrolled,
            ),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'campaign_key' => $this->campaignKey,
            'reason' => $this->reason,
            'on_not_enrolled' => $this->onNotEnrolled,
            'skip_pending_messages' => $this->skipPendingMessages,
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

    private static function invalidReason(
        ?string $campaignKey,
        string $onNotEnrolled,
    ): ?string {
        if ($campaignKey === null) {
            return 'cancel_campaign_missing_campaign_key';
        }

        if (! in_array($onNotEnrolled, self::ON_NOT_ENROLLED_OPTIONS, true)) {
            return 'cancel_campaign_invalid_on_not_enrolled';
        }

        return null;
    }
}