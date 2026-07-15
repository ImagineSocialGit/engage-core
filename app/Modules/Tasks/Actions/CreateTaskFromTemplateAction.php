<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Model;
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
        $explicitLinks = $data['links'] ?? [];

        if (! is_array($explicitLinks)) {
            throw new InvalidArgumentException('Task links must be an array.');
        }

        $resolvedDefaultLinks = $this->resolveLinkDefaults(
            template: $template,
            context: is_array($data['link_context'] ?? null)
                ? $data['link_context']
                : [],
        );

        return $this->createTask->handle([
            'links' => [
                ...$explicitLinks,
                ...$resolvedDefaultLinks,
            ],

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
            'assigned_to_strategy' => $this->assignedToStrategy(
                $data,
                $defaults,
                $template,
            ),
            'assignment_context' => is_array($data['assignment_context'] ?? null)
                ? $data['assignment_context']
                : [],

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

            'task_template_id' => $template->getKey(),
            'task_template_key' => $template->key,

            'source' => $this->value(
                $data,
                $defaults,
                'source',
                Task::SOURCE_MODULE,
            ),
            'title' => $this->value(
                $data,
                $defaults,
                'title',
                $template->title,
            ),
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
            'status' => $this->value(
                $data,
                $defaults,
                'status',
                Task::STATUS_OPEN,
            ),
            'priority' => $this->value(
                $data,
                $defaults,
                'priority',
                $template->priority,
            ),
            'meta' => array_replace_recursive(
                [
                    'task_template' => [
                        'id' => $template->getKey(),
                        'key' => $template->key,
                        'source' => $template->source,
                        'source_version' => $template->source_version,
                    ],
                ],
                is_array($template->meta) ? [
                    'task_template_meta' => $template->meta,
                ] : [],
                is_array($defaults['meta'] ?? null)
                    ? $defaults['meta']
                    : [],
                is_array($data['meta'] ?? null)
                    ? $data['meta']
                    : [],
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{linkable: Model, role: string}>
     */
    private function resolveLinkDefaults(
        TaskTemplate $template,
        array $context,
    ): array {
        $linkDefaults = is_array($template->link_defaults)
            ? $template->link_defaults
            : [];

        $resolved = [];

        foreach ($linkDefaults as $index => $default) {
            if (! is_array($default)) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] link default [{$index}] is invalid."
                );
            }

            $role = $default['role'] ?? null;
            $source = $default['source'] ?? null;

            if (! is_string($role)
                || ! in_array($role, TaskLink::ROLES, true)
            ) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] link default [{$index}] has an invalid role."
                );
            }

            if (! is_string($source)
                || ! in_array($source, TaskTemplate::LINK_SOURCES, true)
            ) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] link default [{$index}] has an invalid source."
                );
            }

            $linkable = $context[$source] ?? null;

            if (! $linkable instanceof Model) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] requires link context [{$source}]."
                );
            }

            if ($source === TaskTemplate::LINK_SOURCE_CURRENT_CONTACT
                && ! $linkable instanceof Contact
            ) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] requires [current_contact] to be a Contact."
                );
            }

            $resolved[] = [
                'linkable' => $linkable,
                'role' => $role,
            ];
        }

        return $resolved;
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

    private function resolveTemplate(
        TaskTemplate|string $template,
    ): TaskTemplate {
        if ($template instanceof TaskTemplate) {
            if (! $template->is_active) {
                throw new InvalidArgumentException(
                    "Task template [{$template->key}] is inactive."
                );
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
            throw (new ModelNotFoundException())->setModel(
                TaskTemplate::class,
                [$template],
            );
        }

        return $resolved;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
