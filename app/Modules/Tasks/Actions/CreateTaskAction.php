<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;
use InvalidArgumentException;

class CreateTaskAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data): Task
    {
        $assignedToId = $this->nullableInt($data['assigned_to_id'] ?? null);
        $responsibleId = $this->nullableInt($data['responsible_id'] ?? null);

        $responsibleParty = $this->responsibleParty($data['responsible_party'] ?? null);

        return Task::query()->create([
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $this->nullableInt($data['related_id'] ?? null),

            'assigned_to_type' => $this->assignedToType(
                assignedToId: $assignedToId,
                assignedToType: $data['assigned_to_type'] ?? null,
            ),
            'assigned_to_id' => $assignedToId,

            'responsible_party' => $responsibleParty,
            'responsible_type' => $responsibleId !== null
                ? ($data['responsible_type'] ?? null)
                : null,
            'responsible_id' => $responsibleId,

            'source' => $data['source'] ?? Task::SOURCE_SYSTEM,
            'title' => $this->requiredString($data['title'] ?? null, 'title'),
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'status' => $data['status'] ?? Task::STATUS_OPEN,
            'priority' => $data['priority'] ?? null,
            'meta' => $data['meta'] ?? null,
        ]);
    }

    private function assignedToType(?int $assignedToId, mixed $assignedToType): ?string
    {
        if ($assignedToId === null) {
            return null;
        }

        if (is_string($assignedToType) && trim($assignedToType) !== '') {
            return trim($assignedToType);
        }

        return TeamMember::class;
    }

    private function responsibleParty(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return Task::RESPONSIBLE_PARTY_INTERNAL;
        }

        $value = trim($value);

        if (! in_array($value, Task::RESPONSIBLE_PARTY_OPTIONS, true)) {
            throw new InvalidArgumentException("Invalid task responsible party [{$value}].");
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing required task field [{$field}].");
        }

        return trim($value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}