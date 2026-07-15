<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateTaskAction
{
    public function __construct(
        private readonly TaskAssignmentStrategyResolver $assignmentStrategies,
        private readonly TaskContactLinkResolver $contactLinks,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): Task
    {
        $source = $this->source($data['source'] ?? Task::SOURCE_MANUAL);
        [$taskTemplateId, $taskTemplateKey] = $this->taskTemplatePair(
            $data['task_template_id'] ?? null,
            $data['task_template_key'] ?? null,
        );

        if ($source !== Task::SOURCE_MANUAL && $taskTemplateId === null) {
            throw new InvalidArgumentException(
                'Automation-created Tasks must be template-backed.'
            );
        }

        $links = $this->normalizeLinks($data['links'] ?? []);

        [$assignedToType, $assignedToId] = $this->assignedToMorphPair(
            assignedToType: $data['assigned_to_type'] ?? null,
            assignedToId: $data['assigned_to_id'] ?? null,
            assignedToStrategy: $data['assigned_to_strategy'] ?? $data['assigned_to'] ?? null,
            context: is_array($data['assignment_context'] ?? null)
                ? $data['assignment_context']
                : [],
        );

        $responsibleParty = $this->responsibleParty(
            $data['responsible_party'] ?? null,
        );

        [$responsibleType, $responsibleId] = $this->optionalMorphPair(
            $data['responsible_type'] ?? null,
            $data['responsible_id'] ?? null,
            'responsible',
        );

        return DB::transaction(function () use (
            $data,
            $source,
            $taskTemplateId,
            $taskTemplateKey,
            $links,
            $assignedToType,
            $assignedToId,
            $responsibleParty,
            $responsibleType,
            $responsibleId,
        ): Task {
            $task = Task::query()->create([
                'assigned_to_type' => $assignedToType,
                'assigned_to_id' => $assignedToId,
                'responsible_party' => $responsibleParty,
                'responsible_type' => $responsibleType,
                'responsible_id' => $responsibleId,
                'task_template_id' => $taskTemplateId,
                'task_template_key' => $taskTemplateKey,
                'source' => $source,
                'title' => $this->requiredString($data['title'] ?? null, 'title'),
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? $this->dueAt($data['due_offset_minutes'] ?? null),
                'status' => $data['status'] ?? Task::STATUS_OPEN,
                'priority' => $data['priority'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            foreach ($links as $link) {
                $task->links()->firstOrCreate($link);
            }

            if ($responsibleParty === Task::RESPONSIBLE_PARTY_CONTACT
                && $responsibleType === null
                && $responsibleId === null
            ) {
                $contact = $this->contactLinks->resolve($task);

                if ($contact) {
                    $task->forceFill([
                        'responsible_type' => $contact->getMorphClass(),
                        'responsible_id' => $contact->getKey(),
                    ])->save();
                }
            }

            return $task->fresh(['links', 'taskTemplate']) ?? $task;
        });
    }

    /**
     * @param array<string, mixed> $context
     * @return array{0: ?string, 1: ?int}
     */
    private function assignedToMorphPair(
        mixed $assignedToType,
        mixed $assignedToId,
        mixed $assignedToStrategy,
        array $context,
    ): array {
        [$type, $id] = $this->optionalMorphPair(
            $assignedToType,
            $assignedToId,
            'assigned_to',
        );

        if ($type !== null && $id !== null) {
            return [$type, $id];
        }

        $strategy = is_string($assignedToStrategy)
            ? trim($assignedToStrategy)
            : null;

        $assignee = $this->assignmentStrategies->resolve($strategy, $context);

        if (! $assignee) {
            return [null, null];
        }

        return [
            $assignee->getMorphClass(),
            (int) $assignee->getKey(),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function optionalMorphPair(
        mixed $type,
        mixed $id,
        string $field,
    ): array {
        $normalizedType = $this->morphType($type);
        $normalizedId = $this->nullableInt($id);

        if ($normalizedType === null && $normalizedId === null) {
            return [null, null];
        }

        if ($normalizedType === null || $normalizedId === null) {
            throw new InvalidArgumentException("Incomplete task {$field} morph.");
        }

        return [$normalizedType, $normalizedId];
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function taskTemplatePair(
        mixed $templateId,
        mixed $templateKey,
    ): array {
        $id = $this->nullableInt($templateId);
        $key = $this->nullableString($templateKey);

        if ($id === null && $key === null) {
            return [null, null];
        }

        if ($id === null || $key === null) {
            throw new InvalidArgumentException(
                'Incomplete task template identity.'
            );
        }

        $exists = TaskTemplate::query()
            ->whereKey($id)
            ->where('key', $key)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException(
                "Task template identity [{$id}:{$key}] is invalid."
            );
        }

        return [$id, $key];
    }

    /**
     * @return array<int, array{
     *     linkable_type: string,
     *     linkable_id: int,
     *     role: string
     * }>
     */
    private function normalizeLinks(mixed $links): array
    {
        if ($links === null) {
            return [];
        }

        if (! is_array($links)) {
            throw new InvalidArgumentException('Task links must be an array.');
        }

        $normalized = [];

        foreach ($links as $index => $link) {
            if (! is_array($link)) {
                throw new InvalidArgumentException(
                    "Task link [{$index}] must be an array."
                );
            }

            $role = $this->requiredString(
                $link['role'] ?? null,
                "links.{$index}.role",
            );

            if (! in_array($role, TaskLink::ROLES, true)) {
                throw new InvalidArgumentException(
                    "Invalid TaskLink role [{$role}]."
                );
            }

            $linkable = $link['linkable'] ?? null;

            if ($linkable instanceof Model) {
                $model = $linkable;
            } else {
                $model = $this->resolveModel(
                    type: $link['linkable_type'] ?? null,
                    id: $link['linkable_id'] ?? null,
                    field: "links.{$index}.linkable",
                );
            }

            $attributes = [
                'linkable_type' => $model->getMorphClass(),
                'linkable_id' => (int) $model->getKey(),
                'role' => $role,
            ];

            $identity = implode(':', [
                $attributes['linkable_type'],
                $attributes['linkable_id'],
                $attributes['role'],
            ]);

            $normalized[$identity] = $attributes;
        }

        return array_values($normalized);
    }

    private function resolveModel(
        mixed $type,
        mixed $id,
        string $field,
    ): Model {
        $modelClass = $this->modelClass($type);
        $modelId = $this->nullableInt($id);

        if ($modelClass === null || $modelId === null) {
            throw new InvalidArgumentException(
                "Incomplete task {$field} morph."
            );
        }

        $model = $modelClass::query()->find($modelId);

        if (! $model instanceof Model) {
            throw new InvalidArgumentException(
                "Task {$field} record does not exist."
            );
        }

        return $model;
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelClass(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $mapped = Relation::getMorphedModel($value) ?? $value;

        return $this->isModelClass($mapped) ? $mapped : null;
    }

    private function responsibleParty(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return Task::RESPONSIBLE_PARTY_INTERNAL;
        }

        $value = trim($value);

        if (! in_array($value, Task::RESPONSIBLE_PARTY_OPTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid task responsible party [{$value}]."
            );
        }

        return $value;
    }

    private function source(mixed $value): string
    {
        $source = $this->requiredString($value, 'source');

        if (! in_array($source, Task::SOURCE_OPTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid task source [{$source}]."
            );
        }

        return $source;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Missing required task field [{$field}]."
            );
        }

        return trim($value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function dueAt(mixed $dueOffsetMinutes): ?CarbonImmutable
    {
        $minutes = $this->nullableInt($dueOffsetMinutes);

        return $minutes === null
            ? null
            : CarbonImmutable::now('UTC')->addMinutes($minutes);
    }

    private function morphType(mixed $value): ?string
    {
        $modelClass = $this->modelClass($value);

        if ($modelClass !== null) {
            return (new $modelClass())->getMorphClass();
        }

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function isModelClass(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class);
    }
}
