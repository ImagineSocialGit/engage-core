<?php

namespace App\Modules\Relationships\Data\Automation;

class ChangeRelationshipStageAutomationDefinition
{
    public const ON_MISSING_RELATIONSHIP_SKIPPED = 'skipped';
    public const ON_MISSING_RELATIONSHIP_COMPLETED = 'completed';
    public const ON_MISSING_RELATIONSHIP_BLOCKED = 'blocked';
    public const ON_MISSING_RELATIONSHIP_FAILED = 'failed';

    public const ON_MISSING_RELATIONSHIP_OPTIONS = [
        self::ON_MISSING_RELATIONSHIP_SKIPPED,
        self::ON_MISSING_RELATIONSHIP_COMPLETED,
        self::ON_MISSING_RELATIONSHIP_BLOCKED,
        self::ON_MISSING_RELATIONSHIP_FAILED,
    ];

    public function __construct(
        public readonly ?string $relationshipKey,
        public readonly ?string $stageKey,
        public readonly string $onMissingRelationship = self::ON_MISSING_RELATIONSHIP_SKIPPED,
        public readonly ?string $invalidReason = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $relationshipKey = self::nullableString($input['relationship_key'] ?? null);
        $stageKey = self::nullableString($input['stage_key'] ?? null);
        $onMissing = self::nullableString($input['on_missing_relationship'] ?? null)
            ?? self::ON_MISSING_RELATIONSHIP_SKIPPED;

        return new self(
            relationshipKey: $relationshipKey,
            stageKey: $stageKey,
            onMissingRelationship: $onMissing,
            invalidReason: match (true) {
                $relationshipKey === null => 'change_relationship_stage_missing_relationship_key',
                $stageKey === null => 'change_relationship_stage_missing_stage_key',
                ! in_array($onMissing, self::ON_MISSING_RELATIONSHIP_OPTIONS, true) => 'change_relationship_stage_invalid_on_missing_relationship',
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
            'relationship_key' => $this->relationshipKey,
            'stage_key' => $this->stageKey,
            'on_missing_relationship' => $this->onMissingRelationship,
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