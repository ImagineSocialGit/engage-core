<?php

namespace App\Modules\Events\Data;

use InvalidArgumentException;

final readonly class EventDefinitionContribution
{
    public const CATEGORY_EVENT_TYPE = 'event_type';

    public const CATEGORY_STAKEHOLDER_ROLE = 'stakeholder_role';

    public const CATEGORY_EXTERNAL_REFERENCE_PROVIDER = 'external_reference_provider';

    public const CATEGORY_EXTERNAL_REFERENCE_TYPE = 'external_reference_type';

    public const CATEGORY_ATTENDANCE_SOURCE = 'attendance_source';

    public const CATEGORIES = [
        self::CATEGORY_EVENT_TYPE,
        self::CATEGORY_STAKEHOLDER_ROLE,
        self::CATEGORY_EXTERNAL_REFERENCE_PROVIDER,
        self::CATEGORY_EXTERNAL_REFERENCE_TYPE,
        self::CATEGORY_ATTENDANCE_SOURCE,
    ];

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $category,
        public string $key,
        public string $label,
        public ?string $description = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
        public array $meta = [],
    ) {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(
                "Unsupported Event definition category [{$category}]."
            );
        }

        if (! preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $key)) {
            throw new InvalidArgumentException(
                "Event definition key [{$key}] must use lowercase snake_case."
            );
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] must have a non-empty label."
            );
        }

        if ($description !== null && trim($description) === '') {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] description must be null or non-empty."
            );
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] sort order cannot be negative."
            );
        }

        if (array_is_list($meta) && $meta !== []) {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] metadata must be a map."
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromConfig(
        string $category,
        string $key,
        array $definition,
    ): self {
        return new self(
            category: $category,
            key: $key,
            label: self::requiredString($definition, 'label', $category, $key),
            description: self::nullableString($definition['description'] ?? null),
            sortOrder: self::integer($definition['sort_order'] ?? 0, 'sort_order', $category, $key),
            isActive: self::boolean($definition['is_active'] ?? true, 'is_active', $category, $key),
            meta: self::map($definition['meta'] ?? [], 'meta', $category, $key),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function requiredString(
        array $definition,
        string $field,
        string $category,
        string $key,
    ): string {
        $value = $definition[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] field [{$field}] must be a non-empty string."
            );
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                'Event definition description must be null or a non-empty string.'
            );
        }

        return trim($value);
    }

    private static function integer(
        mixed $value,
        string $field,
        string $category,
        string $key,
    ): int {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] field [{$field}] must be an integer."
            );
        }

        return (int) $value;
    }

    private static function boolean(
        mixed $value,
        string $field,
        string $category,
        string $key,
    ): bool {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] field [{$field}] must be boolean."
            );
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(
        mixed $value,
        string $field,
        string $category,
        string $key,
    ): array {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException(
                "Event definition [{$category}:{$key}] field [{$field}] must be a map."
            );
        }

        return $value;
    }
}