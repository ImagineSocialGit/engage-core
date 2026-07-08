<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class CreateTaskFromTemplateAction
{
    public function __construct(private readonly CreateTaskAction $createTask) {}

    /** @param array<string, mixed> $data */
    public function handle(TaskTemplate|string $template, array $data = []): Task
    {
        $template = $this->resolveTemplate($template);

        return $this->createTask->handle([
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'assigned_to_type' => $data['assigned_to_type'] ?? $template->assigned_to_type,
            'assigned_to_id' => $data['assigned_to_id'] ?? $template->assigned_to_id,
            'assigned_to_strategy' => $data['assigned_to_strategy'] ?? $data['assigned_to'] ?? $template->assigned_to_strategy,
            'responsible_party' => $data['responsible_party'] ?? $template->responsible_party,
            'responsible_type' => $data['responsible_type'] ?? $template->responsible_type,
            'responsible_id' => $data['responsible_id'] ?? $template->responsible_id,
            'flow_route_progress_id' => $data['flow_route_progress_id'] ?? null,
            'flow_route_plan_id' => $data['flow_route_plan_id'] ?? null,
            'flow_route_plan_item_id' => $data['flow_route_plan_item_id'] ?? null,
            'flow_route_progress_item_id' => $data['flow_route_progress_item_id'] ?? null,
            'flow_route_id' => $data['flow_route_id'] ?? null,
            'flow_route_point_id' => $data['flow_route_point_id'] ?? null,
            'flow_route_capability_id' => $data['flow_route_capability_id'] ?? null,
            'task_template_id' => $template->getKey(),
            'task_template_key' => $template->key,
            'source' => $data['source'] ?? Task::SOURCE_MODULE,
            'title' => $data['title'] ?? $template->title,
            'description' => $data['description'] ?? $template->task_description ?? $template->description,
            'due_at' => $data['due_at'] ?? null,
            'due_offset_minutes' => $this->nullableInt($data['due_offset_minutes'] ?? null) ?? $template->due_offset_minutes,
            'status' => $data['status'] ?? Task::STATUS_OPEN,
            'priority' => $data['priority'] ?? $template->priority,
            'meta' => array_replace_recursive([
                'task_template' => [
                    'id' => $template->getKey(),
                    'key' => $template->key,
                    'group_key' => $template->group_key,
                    'source' => $template->source,
                    'source_version' => $template->source_version,
                ],
            ], is_array($template->meta) ? ['task_template_meta' => $template->meta] : [], is_array($data['meta'] ?? null) ? $data['meta'] : []),
        ]);
    }

    private function resolveTemplate(TaskTemplate|string $template): TaskTemplate
    {
        if ($template instanceof TaskTemplate) {
            if (! $template->is_active) throw new InvalidArgumentException("Task template [{$template->key}] is inactive.");
            return $template;
        }

        $template = trim($template);
        if ($template === '') throw new InvalidArgumentException('Missing task template key.');

        $resolved = TaskTemplate::query()->active()->forKey($template)->first();
        if (! $resolved instanceof TaskTemplate) throw (new ModelNotFoundException())->setModel(TaskTemplate::class, [$template]);
        return $resolved;
    }

    private function nullableInt(mixed $value): ?int { return is_numeric($value) ? (int) $value : null; }
}
