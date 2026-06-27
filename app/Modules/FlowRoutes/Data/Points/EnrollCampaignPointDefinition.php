<?php

namespace App\Modules\FlowRoutes\Data\Points;

class EnrollCampaignPointDefinition
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

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $values = array_replace_recursive($definition, $settings);

        $campaignKey = self::nullableString($values['campaign_key'] ?? null);

        $onAlreadyEnrolled = self::nullableString($values['on_already_enrolled'] ?? null)
            ?? self::ON_ALREADY_ENROLLED_SKIPPED;

        $payload = is_array($values['payload'] ?? null)
            ? $values['payload']
            : [];

        $meta = is_array($values['meta'] ?? null)
            ? $values['meta']
            : [];

        $startContext = is_array($values['start_context'] ?? null)
            ? $values['start_context']
            : null;

        $exitConditions = is_array($values['exit_conditions'] ?? null)
            ? $values['exit_conditions']
            : null;

        return new self(
            campaignKey: $campaignKey,
            onAlreadyEnrolled: $onAlreadyEnrolled,
            payload: $payload,
            meta: $meta,
            startContext: $startContext,
            exitConditions: $exitConditions,
            invalidReason: self::invalidReason(
                campaignKey: $campaignKey,
                onAlreadyEnrolled: $onAlreadyEnrolled,
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

    private static function invalidReason(
        ?string $campaignKey,
        string $onAlreadyEnrolled,
    ): ?string {
        if ($campaignKey === null) {
            return 'enroll_campaign_missing_campaign_key';
        }

        if (! in_array($onAlreadyEnrolled, self::ON_ALREADY_ENROLLED_OPTIONS, true)) {
            return 'enroll_campaign_invalid_on_already_enrolled';
        }

        return null;
    }
}