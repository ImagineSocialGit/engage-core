<?php

namespace App\Modules\FlowRoutes\Data;

class CreateTaskPointDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?int $assignedToId,
        public readonly ?string $description = null,
        public readonly mixed $dueAt = null,
        public readonly ?string $priority = null,
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $source = array_replace_recursive($definition, $settings);

        $title = self::string($source, 'title');

        if ($title === null) {
            return new self(
                title: null,
                assignedToId: self::int($source, 'assigned_to_id'),
                invalidReason: 'create_task_missing_title',
                meta: self::meta($source),
            );
        }

        $assignedToId = self::int($source, 'assigned_to_id');

        if ($assignedToId === null) {
            return new self(
                title: $title,
                assignedToId: null,
                description: self::string($source, 'description'),
                dueAt: $source['due_at'] ?? null,
                priority: self::string($source, 'priority'),
                invalidReason: 'create_task_missing_assigned_to_id',
                meta: self::meta($source),
            );
        }

        return new self(
            title: $title,
            assignedToId: $assignedToId,
            description: self::string($source, 'description'),
            dueAt: $source['due_at'] ?? null,
            priority: self::string($source, 'priority'),
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && is_string($this->title)
            && trim($this->title) !== ''
            && $this->assignedToId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'title' => $this->title,
            'assigned_to_id' => $this->assignedToId,
            'description' => $this->description,
            'due_at' => $this->dueAt,
            'priority' => $this->priority,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function int(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function meta(array $source): array
    {
        $meta = $source['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}