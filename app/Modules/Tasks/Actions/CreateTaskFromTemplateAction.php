<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class CreateTaskFromTemplateAction
{
    public function __construct(
        private readonly CreateTaskAction $createTask,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(TaskTemplate|string $template, array $data = []): Task
    {
        $template = $this->resolveTemplate($template);
        $defaults = is_array($template->defaults) ? $template->defaults : [];

        return $this->createTask->handle([
            'related_type' => $this->value($data, $defaults, 'related_type'),
            'related_id' => $this->value($data, $defaults, 'related_id'),

            'assigned_to_type' => $this->value(
                $data,
                $defaults,
                'assigned_to_type',
                $template->assigned_to_type,
            ),
            'assigned_to_id' => $this->value(
                $data,
                $defaults,
                'assigned_to_id',
                $template->assigned_to_id,
            ),
            'assigned_to_strategy' => $this->assignedToStrategy($data, $defaults, $template),

            'responsible_party' => $this->value(
                $data,
                $defaults,
                'responsible_party',
                $template->responsible_party,
            ),
            'responsible_type' => $this->value(
                $data,
                $defaults,
                'responsible_type',
                $template->responsible_type,
            ),
            'responsible_id' => $this->value(
                $data,
                $defaults,
                'responsible_id',
                $template->responsible_id,
            ),

            'flow_route_progress_id' => $data['flow_route_progress_id'] ?? null,
            'flow_route_plan_id' => $data['flow_route_plan_id'] ?? null,
            'flow_route_plan_item_id' => $data['flow_route_plan_item_id'] ?? null,
            'flow_route_progress_item_id' => $data['flow_route_progress_item_id'] ?? null,
            'flow_route_id' => $data['flow_route_id'] ?? null,
            'flow_route_point_id' => $data['flow_route_point_id'] ?? null,
            'flow_route_capability_id' => $data['flow_route_capability_id'] ?? null,

            'task_template_id' => $template->getKey(),
            'task_template_key' => $template->key,

            'source' => $this->value($data, $defaults, 'source', Task::SOURCE_MODULE),
            'title' => $this->value($data, $defaults, 'title', $template->title),
            'description' => $this->value(
                $data,
                $defaults,
                'description',
                $template->task_description ?? $template->description,
            ),
            'due_at' => $this->value($data, $defaults, 'due_at'),
            'due_offset_minutes' => $this->nullableInt(
                $this->value(
                    $data,
                    $defaults,
                    'due_offset_minutes',
                    $template->due_offset_minutes,
                ),
            ),
            'status' => $this->value($data, $defaults, 'status', Task::STATUS_OPEN),
            'priority' => $this->value($data, $defaults, 'priority', $template->priority),
            'meta' => array_replace_recursive(
                [
                    'task_template' => [
                        'id' => $template->getKey(),
                        'key' => $template->key,
                        'group_key' => $template->group_key,
                        'source' => $template->source,
                        'source_version' => $template->source_version,
                    ],
                ],
                is_array($template->meta) ? [
                    'task_template_meta' => $template->meta,
                ] : [],
                is_array($defaults['meta'] ?? null) ? $defaults['meta'] : [],
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
            ),
        ]);
    }

    /**
     * Caller data wins, then the template's first-class field, then generic defaults.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function value(
        array $data,
        array $defaults,
        string $key,
        mixed $templateValue = null,
    ): mixed {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        if ($templateValue !== null) {
            return $templateValue;
        }

        return $defaults[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function assignedToStrategy(
        array $data,
        array $defaults,
        TaskTemplate $template,
    ): mixed {
        if (array_key_exists('assigned_to_strategy', $data)) {
            return $data['assigned_to_strategy'];
        }

        if (array_key_exists('assigned_to', $data)) {
            return $data['assigned_to'];
        }

        if ($template->assigned_to_strategy !== null) {
            return $template->assigned_to_strategy;
        }

        if (array_key_exists('assigned_to_strategy', $defaults)) {
            return $defaults['assigned_to_strategy'];
        }

        return $defaults['assigned_to'] ?? null;
    }

    private function resolveTemplate(TaskTemplate|string $template): TaskTemplate
    {
        if ($template instanceof TaskTemplate) {
            if (! $template->is_active) {
                throw new InvalidArgumentException("Task template [{$template->key}] is inactive.");
            }

            return $template;
        }

        $template = trim($template);

        if ($template === '') {
            throw new InvalidArgumentException('Missing task template key.');
        }

        $resolved = TaskTemplate::query()
            ->active()
            ->forKey($template)
            ->first();

        if (! $resolved instanceof TaskTemplate) {
            throw (new ModelNotFoundException())->setModel(TaskTemplate::class, [$template]);
        }

        return $resolved;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
