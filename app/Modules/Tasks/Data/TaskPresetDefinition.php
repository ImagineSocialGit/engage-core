<?php

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\Task;

class TaskPresetDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly ?string $key,
        public readonly ?string $name,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $taskDescription,
        public readonly string $responsibleParty,
        public readonly ?string $priority,
        public readonly ?int $dueOffsetDays,
        public readonly bool $isActive,
        public readonly array $meta = [],
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $groupKey, array $data): self
    {
        $responsibleParty = self::string($data, 'responsible_party')
            ?? Task::RESPONSIBLE_PARTY_INTERNAL;

        if (! in_array($responsibleParty, Task::RESPONSIBLE_PARTY_OPTIONS, true)) {
            return new self(
                groupKey: $groupKey,
                key: self::string($data, 'key'),
                name: self::string($data, 'name'),
                title: self::string($data, 'title'),
                description: self::string($data, 'description'),
                taskDescription: self::string($data, 'task_description'),
                responsibleParty: $responsibleParty,
                priority: self::string($data, 'priority'),
                dueOffsetDays: self::nullableInt($data, 'due_offset_days'),
                isActive: self::bool($data, 'is_active', true),
                meta: self::meta($data),
                invalidReason: 'invalid_responsible_party',
            );
        }

        $key = self::string($data, 'key');
        $name = self::string($data, 'name');
        $title = self::string($data, 'title');

        $invalidReason = match (true) {
            $key === null => 'missing_key',
            $name === null => 'missing_name',
            $title === null => 'missing_title',
            default => null,
        };

        return new self(
            groupKey: $groupKey,
            key: $key,
            name: $name,
            title: $title,
            description: self::string($data, 'description'),
            taskDescription: self::string($data, 'task_description'),
            responsibleParty: $responsibleParty,
            priority: self::string($data, 'priority'),
            dueOffsetDays: self::nullableInt($data, 'due_offset_days'),
            isActive: self::bool($data, 'is_active', true),
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
            'group_key' => $this->groupKey,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'task_description' => $this->taskDescription,
            'responsible_party' => $this->responsibleParty,
            'priority' => $this->priority,
            'due_offset_days' => $this->dueOffsetDays,
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
    private static function bool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
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