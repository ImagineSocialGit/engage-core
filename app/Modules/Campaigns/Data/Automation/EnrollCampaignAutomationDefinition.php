<?php

namespace App\Modules\Campaigns\Data\Automation;

class EnrollCampaignAutomationDefinition
{
    public const ON_ALREADY_ENROLLED_SKIPPED = 'skipped';
    public const ON_ALREADY_ENROLLED_COMPLETED = 'completed';
    public const ON_ALREADY_ENROLLED_BLOCKED = 'blocked';
    public const ON_ALREADY_ENROLLED_FAILED = 'failed';

    public const ON_ALREADY_ENROLLED_OPTIONS = [
        self::ON_ALREADY_ENROLLED_SKIPPED,
        self::ON_ALREADY_ENROLLED_COMPLETED,
        self::ON_ALREADY_ENROLLED_BLOCKED,
        self::ON_ALREADY_ENROLLED_FAILED,
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed>|null $exitConditions
     */
    public function __construct(
        public readonly ?string $campaignKey,
        public readonly string $onAlreadyEnrolled = self::ON_ALREADY_ENROLLED_SKIPPED,
        public readonly array $payload = [],
        public readonly array $meta = [],
        public readonly ?array $startContext = null,
        public readonly ?array $exitConditions = null,
        public readonly ?string $invalidReason = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $campaignKey = self::nullableString($input['campaign_key'] ?? null);
        $onAlreadyEnrolled = self::nullableString($input['on_already_enrolled'] ?? null)
            ?? self::ON_ALREADY_ENROLLED_SKIPPED;

        return new self(
            campaignKey: $campaignKey,
            onAlreadyEnrolled: $onAlreadyEnrolled,
            payload: is_array($input['payload'] ?? null) ? $input['payload'] : [],
            meta: is_array($input['meta'] ?? null) ? $input['meta'] : [],
            startContext: is_array($input['start_context'] ?? null) ? $input['start_context'] : null,
            exitConditions: is_array($input['exit_conditions'] ?? null) ? $input['exit_conditions'] : null,
            invalidReason: match (true) {
                $campaignKey === null => 'enroll_campaign_missing_campaign_key',
                ! in_array($onAlreadyEnrolled, self::ON_ALREADY_ENROLLED_OPTIONS, true) => 'enroll_campaign_invalid_on_already_enrolled',
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
            'on_already_enrolled' => $this->onAlreadyEnrolled,
            'payload' => $this->payload,
            'meta' => $this->meta,
            'start_context' => $this->startContext,
            'exit_conditions' => $this->exitConditions,
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
