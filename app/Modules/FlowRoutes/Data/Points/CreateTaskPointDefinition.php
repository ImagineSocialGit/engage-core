<?php

namespace App\Modules\FlowRoutes\Data\Points;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;

class CreateTaskPointDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $taskTemplateKey = null,
        public readonly ?int $assignedToId = null,
        public readonly ?string $assignedToType = null,
        public readonly ?string $assignedTo = null,
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
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $source = array_replace_recursive($definition, $settings);

        $title = self::string($source, 'title');
        $taskTemplateKey = self::string($source, 'task_template_key')
            ?? self::string($source, 'template_key');
        $responsibleParty = self::string($source, 'responsible_party')
            ?? ($taskTemplateKey === null ? Task::RESPONSIBLE_PARTY_INTERNAL : null);
        $assignedToStrategy = self::string($source, 'assigned_to_strategy')
            ?? self::string($source, 'assigned_to');

        $invalidReason = match (true) {
            $title === null && $taskTemplateKey === null => 'create_task_missing_title_or_template',
            $responsibleParty !== null && ! in_array($responsibleParty, Task::RESPONSIBLE_PARTY_OPTIONS, true) => 'create_task_invalid_responsible_party',
            $assignedToStrategy !== null && ! in_array($assignedToStrategy, TaskTemplate::ASSIGNED_TO_STRATEGIES, true) => 'create_task_invalid_assigned_to_strategy',
            default => null,
        };

        return new self(
            title: $title,
            taskTemplateKey: $taskTemplateKey,
            assignedToId: self::int($source, 'assigned_to_id'),
            assignedToType: self::string($source, 'assigned_to_type'),
            assignedTo: self::string($source, 'assigned_to'),
            assignedToStrategy: $assignedToStrategy,
            responsibleParty: $responsibleParty,
            responsibleType: self::string($source, 'responsible_type'),
            responsibleId: self::int($source, 'responsible_id'),
            description: self::string($source, 'description'),
            dueAt: $source['due_at'] ?? null,
            dueOffsetMinutes: self::dueOffsetMinutes($source),
            priority: self::string($source, 'priority'),
            invalidReason: $invalidReason,
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && (
                (is_string($this->title) && trim($this->title) !== '')
                || (is_string($this->taskTemplateKey) && trim($this->taskTemplateKey) !== '')
            );
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
            'assigned_to' => $this->assignedTo,
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
     */
    private static function dueOffsetMinutes(array $source): ?int
    {
        $minutes = self::int($source, 'due_offset_minutes');

        if ($minutes !== null) {
            return $minutes;
        }

        $days = self::int($source, 'due_offset_days');

        return $days !== null ? $days * 1440 : null;
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
