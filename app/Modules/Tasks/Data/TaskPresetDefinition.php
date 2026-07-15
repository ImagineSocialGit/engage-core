<?php

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;

class TaskPresetDefinition
{
    /**
     * @param array<int, array<string, mixed>>|null $linkDefaults
     * @param array<string, mixed>|null $defaults
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $key,
        public readonly ?string $name,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $taskDescription,
        public readonly string $responsibleParty,
        public readonly ?string $responsibleType,
        public readonly ?int $responsibleId,
        public readonly ?string $assignedToType,
        public readonly ?int $assignedToId,
        public readonly ?string $assignedToStrategy,
        public readonly ?string $priority,
        public readonly ?int $dueOffsetMinutes,
        public readonly string $source,
        public readonly ?string $sourceVersion,
        public readonly ?string $ownerGroup,
        public readonly ?string $category,
        public readonly bool $isActive,
        public readonly ?array $linkDefaults = null,
        public readonly ?array $defaults = null,
        public readonly array $meta = [],
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $responsibleParty = self::string($data, 'responsible_party')
            ?? Task::RESPONSIBLE_PARTY_INTERNAL;

        $assignedToStrategy = self::string($data, 'assigned_to_strategy')
            ?? self::string($data, 'assigned_to');

        $key = self::string($data, 'key');
        $title = self::string($data, 'title');
        $name = self::string($data, 'name') ?? $title;

        $invalidReason = match (true) {
            $key === null => 'missing_key',
            $title === null => 'missing_title',
            ! in_array(
                $responsibleParty,
                Task::RESPONSIBLE_PARTY_OPTIONS,
                true,
            ) => 'invalid_responsible_party',
            default => null,
        };

        return new self(
            key: $key,
            name: $name,
            title: $title,
            description: self::string($data, 'description'),
            taskDescription: self::string($data, 'task_description'),
            responsibleParty: $responsibleParty,
            responsibleType: self::string($data, 'responsible_type'),
            responsibleId: self::nullableInt($data, 'responsible_id'),
            assignedToType: self::string($data, 'assigned_to_type'),
            assignedToId: self::nullableInt($data, 'assigned_to_id'),
            assignedToStrategy: $assignedToStrategy,
            priority: self::string($data, 'priority'),
            dueOffsetMinutes: self::dueOffsetMinutes($data),
            source: self::string($data, 'source')
                ?? TaskTemplate::SOURCE_PRESET,
            sourceVersion: self::string($data, 'source_version')
                ?? self::string($data, 'version'),
            ownerGroup: self::string($data, 'owner_group'),
            category: self::string($data, 'category'),
            isActive: self::bool($data, 'is_active', true),
            linkDefaults: self::listOrNull($data, 'link_defaults'),
            defaults: self::arrayOrNull($data, 'defaults'),
            meta: self::meta($data),
            invalidReason: $invalidReason,
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && $this->key !== null
            && $this->name !== null
            && $this->title !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'source' => $this->source,
            'source_version' => $this->sourceVersion,
            'owner_group' => $this->ownerGroup,
            'category' => $this->category,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'task_description' => $this->taskDescription,
            'assigned_to_type' => $this->assignedToType,
            'assigned_to_id' => $this->assignedToId,
            'assigned_to_strategy' => $this->assignedToStrategy,
            'responsible_party' => $this->responsibleParty,
            'responsible_type' => $this->responsibleType,
            'responsible_id' => $this->responsibleId,
            'priority' => $this->priority,
            'due_offset_minutes' => $this->dueOffsetMinutes,
            'link_defaults' => $this->linkDefaults,
            'defaults' => $this->defaults,
            'is_active' => $this->isActive,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function bool(
        array $data,
        string $key,
        bool $default,
    ): bool {
        $value = $data[$key] ?? $default;

        return is_bool($value)
            ? $value
            : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function dueOffsetMinutes(array $data): ?int
    {
        $minutes = self::nullableInt($data, 'due_offset_minutes');

        if ($minutes !== null) {
            return $minutes;
        }

        $days = self::nullableInt($data, 'due_offset_days');

        if ($days !== null) {
            return $days * 1440;
        }

        $due = $data['defaults']['due'] ?? null;

        if (! is_array($due)) {
            return null;
        }

        $minutes = is_numeric($due['minutes'] ?? null)
            ? (int) $due['minutes']
            : 0;
        $hours = is_numeric($due['hours'] ?? null)
            ? (int) $due['hours']
            : 0;
        $days = is_numeric($due['days'] ?? null)
            ? (int) $due['days']
            : 0;

        $total = $minutes + ($hours * 60) + ($days * 1440);

        return $total > 0 ? $total : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function arrayOrNull(
        array $data,
        string $key,
    ): ?array {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>|null
     */
    private static function listOrNull(
        array $data,
        string $key,
    ): ?array {
        $value = $data[$key] ?? null;

        return is_array($value) && array_is_list($value)
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function meta(array $data): array
    {
        $meta = $data['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}
