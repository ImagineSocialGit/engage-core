<?php

namespace App\Modules\Tasks\Data\Automation;

class CreateTaskAutomationDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $taskTemplateKey,
        public readonly ?string $title = null,
        public readonly ?int $assignedToId = null,
        public readonly ?string $assignedToType = null,
        public readonly ?string $assignedToStrategy = null,
        public readonly ?string $responsibleParty = null,
        public readonly ?string $responsibleType = null,
        public readonly ?int $responsibleId = null,
        public readonly ?string $description = null,
        public readonly mixed $dueAt = null,
        public readonly ?int $dueOffsetMinutes = null,
        public readonly ?string $priority = null,
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public static function from(array $input): self
    {
        $taskTemplateKey = self::string($input, 'task_template_key')
            ?? self::string($input, 'template_key');

        return new self(
            taskTemplateKey: $taskTemplateKey,
            title: self::string($input, 'title'),
            assignedToId: self::int($input, 'assigned_to_id'),
            assignedToType: self::string($input, 'assigned_to_type'),
            assignedToStrategy: self::string($input, 'assigned_to_strategy')
                ?? self::string($input, 'assigned_to'),
            responsibleParty: self::string($input, 'responsible_party'),
            responsibleType: self::string($input, 'responsible_type'),
            responsibleId: self::int($input, 'responsible_id'),
            description: self::string($input, 'description'),
            dueAt: $input['due_at'] ?? null,
            dueOffsetMinutes: self::dueOffsetMinutes($input),
            priority: self::string($input, 'priority'),
            invalidReason: $taskTemplateKey === null
                ? 'create_task_requires_task_template'
                : null,
            meta: is_array($input['meta'] ?? null) ? $input['meta'] : [],
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && is_string($this->taskTemplateKey)
            && trim($this->taskTemplateKey) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'task_template_key' => $this->taskTemplateKey,
            'title' => $this->title,
            'assigned_to_id' => $this->assignedToId,
            'assigned_to_type' => $this->assignedToType,
            'assigned_to_strategy' => $this->assignedToStrategy,
            'responsible_party' => $this->responsibleParty,
            'responsible_type' => $this->responsibleType,
            'responsible_id' => $this->responsibleId,
            'description' => $this->description,
            'due_at' => $this->dueAt,
            'due_offset_minutes' => $this->dueOffsetMinutes,
            'priority' => $this->priority,
            'meta' => $this->meta,
        ];
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $input */
    private static function int(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $input */
    private static function dueOffsetMinutes(array $input): ?int
    {
        $minutes = self::int($input, 'due_offset_minutes');

        if ($minutes !== null) {
            return $minutes;
        }

        $days = self::int($input, 'due_offset_days');

        return $days !== null ? $days * 1440 : null;
    }
}
