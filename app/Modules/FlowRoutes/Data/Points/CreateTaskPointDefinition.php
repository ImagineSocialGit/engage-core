<?php

namespace App\Modules\FlowRoutes\Data\Points;

use App\Modules\Tasks\Models\Task;

class CreateTaskPointDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?int $assignedToId = null,
        public readonly ?string $assignedToType = null,
        public readonly ?string $responsibleParty = null,
        public readonly ?string $responsibleType = null,
        public readonly ?int $responsibleId = null,
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
        $responsibleParty = self::string($source, 'responsible_party')
            ?? Task::RESPONSIBLE_PARTY_INTERNAL;

        if ($title === null) {
            return new self(
                title: null,
                assignedToId: self::int($source, 'assigned_to_id'),
                assignedToType: self::string($source, 'assigned_to_type'),
                responsibleParty: $responsibleParty,
                responsibleType: self::string($source, 'responsible_type'),
                responsibleId: self::int($source, 'responsible_id'),
                invalidReason: 'create_task_missing_title',
                meta: self::meta($source),
            );
        }

        if (! in_array($responsibleParty, Task::RESPONSIBLE_PARTY_OPTIONS, true)) {
            return new self(
                title: $title,
                assignedToId: self::int($source, 'assigned_to_id'),
                assignedToType: self::string($source, 'assigned_to_type'),
                responsibleParty: $responsibleParty,
                responsibleType: self::string($source, 'responsible_type'),
                responsibleId: self::int($source, 'responsible_id'),
                description: self::string($source, 'description'),
                dueAt: $source['due_at'] ?? null,
                priority: self::string($source, 'priority'),
                invalidReason: 'create_task_invalid_responsible_party',
                meta: self::meta($source),
            );
        }

        return new self(
            title: $title,
            assignedToId: self::int($source, 'assigned_to_id'),
            assignedToType: self::string($source, 'assigned_to_type'),
            responsibleParty: $responsibleParty,
            responsibleType: self::string($source, 'responsible_type'),
            responsibleId: self::int($source, 'responsible_id'),
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
            && trim($this->title) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'title' => $this->title,
            'assigned_to_id' => $this->assignedToId,
            'assigned_to_type' => $this->assignedToType,
            'responsible_party' => $this->responsibleParty,
            'responsible_type' => $this->responsibleType,
            'responsible_id' => $this->responsibleId,
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