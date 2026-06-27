<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;

class CreateTaskAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data): Task
    {
        return Task::query()->create([
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'assigned_to_type' => $data['assigned_to_type'] ?? TeamMember::class,
            'assigned_to_id' => $data['assigned_to_id'],
            'source' => $data['source'] ?? Task::SOURCE_SYSTEM,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'status' => $data['status'] ?? Task::STATUS_OPEN,
            'priority' => $data['priority'] ?? null,
            'meta' => $data['meta'] ?? null,
        ]);
    }
}