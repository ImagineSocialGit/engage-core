<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayLane
{
    public const SCOPE_STANDARD = 'standard';

    public const SCOPE_RELATIONSHIP = 'relationship';

    public function __construct(
        public string $subjectKey,
        public string $key,
        public string $label,
        public string $scope,
        public ?string $relationshipKey = null,
        public int $sortOrder = 100,
    ) {}

    public static function standard(string $subjectKey = 'contacts'): self
    {
        return new self(
            subjectKey: $subjectKey,
            key: "{$subjectKey}:standard",
            label: 'Standard contacts',
            scope: self::SCOPE_STANDARD,
            sortOrder: 10,
        );
    }

    public static function relationship(
        ?string $relationshipKey = null,
        ?string $relationshipLabel = null,
        string $subjectKey = 'contacts',
    ): self {
        $relationshipKey = is_string($relationshipKey) && trim($relationshipKey) !== ''
            ? trim($relationshipKey)
            : null;
        $relationshipLabel = is_string($relationshipLabel) && trim($relationshipLabel) !== ''
            ? trim($relationshipLabel)
            : null;

        return new self(
            subjectKey: $subjectKey,
            key: $relationshipKey === null
                ? "{$subjectKey}:relationship"
                : "{$subjectKey}:relationship:".rawurlencode($relationshipKey),
            label: $relationshipLabel === null
                ? 'Relationship-based contacts'
                : "{$relationshipLabel} relationships",
            scope: self::SCOPE_RELATIONSHIP,
            relationshipKey: $relationshipKey,
            sortOrder: 20,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject_key' => $this->subjectKey,
            'key' => $this->key,
            'label' => $this->label,
            'scope' => $this->scope,
            'relationship_key' => $this->relationshipKey,
            'sort_order' => $this->sortOrder,
        ];
    }
}