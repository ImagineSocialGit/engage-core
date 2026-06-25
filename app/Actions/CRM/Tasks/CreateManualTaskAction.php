<?php

namespace App\Actions\CRM\Tasks;

use App\Models\Task;
use App\Models\TeamMember;

class CreateManualTaskAction
{
    public function handle(array $data): Task
    {
        return Task::query()->create([
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => $data['assigned_to_id'],
            'source' => Task::SOURCE_MANUAL,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'status' => Task::STATUS_OPEN,
        ]);
    }
}