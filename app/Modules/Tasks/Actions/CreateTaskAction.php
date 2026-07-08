<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

class CreateTaskAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Task
    {
        [$relatedType, $relatedId] = $this->optionalMorphPair($data['related_type'] ?? null, $data['related_id'] ?? null, 'related');
        [$assignedToType, $assignedToId] = $this->assignedToMorphPair($data['assigned_to_type'] ?? null, $data['assigned_to_id'] ?? null, $data['assigned_to_strategy'] ?? $data['assigned_to'] ?? null);
        $responsibleParty = $this->responsibleParty($data['responsible_party'] ?? null);
        [$responsibleType, $responsibleId] = $this->responsibleMorphPair($responsibleParty, $data['responsible_type'] ?? null, $data['responsible_id'] ?? null, $relatedType, $relatedId);

        return Task::query()->create([
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'assigned_to_type' => $assignedToType,
            'assigned_to_id' => $assignedToId,
            'responsible_party' => $responsibleParty,
            'responsible_type' => $responsibleType,
            'responsible_id' => $responsibleId,
            'flow_route_progress_id' => $this->nullableInt($data['flow_route_progress_id'] ?? null),
            'flow_route_plan_id' => $this->nullableInt($data['flow_route_plan_id'] ?? null),
            'flow_route_plan_item_id' => $this->nullableInt($data['flow_route_plan_item_id'] ?? null),
            'flow_route_progress_item_id' => $this->nullableInt($data['flow_route_progress_item_id'] ?? null),
            'flow_route_id' => $this->nullableInt($data['flow_route_id'] ?? null),
            'flow_route_point_id' => $this->nullableInt($data['flow_route_point_id'] ?? null),
            'flow_route_capability_id' => $this->nullableInt($data['flow_route_capability_id'] ?? null),
            'task_template_id' => $this->nullableInt($data['task_template_id'] ?? null),
            'task_template_key' => $this->nullableString($data['task_template_key'] ?? null),
            'source' => $data['source'] ?? Task::SOURCE_SYSTEM,
            'title' => $this->requiredString($data['title'] ?? null, 'title'),
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? $this->dueAt($data['due_offset_minutes'] ?? null),
            'status' => $data['status'] ?? Task::STATUS_OPEN,
            'priority' => $data['priority'] ?? null,
            'meta' => $data['meta'] ?? null,
        ]);
    }

    private function assignedToMorphPair(mixed $assignedToType, mixed $assignedToId, mixed $assignedToStrategy = null): array
    {
        $id = $this->nullableInt($assignedToId);
        if ($id !== null) return [$this->morphType($assignedToType) ?? $this->morphType(TeamMember::class), $id];
        $strategy = is_string($assignedToStrategy) ? trim($assignedToStrategy) : null;
        if ($strategy === null || $strategy === '' || $strategy === TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED) return [null, null];
        if ($strategy !== TaskTemplate::ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER) throw new InvalidArgumentException("Invalid task assignment strategy [{$strategy}].");
        $teamMembers = TeamMember::query()->active()->get();
        if ($teamMembers->count() !== 1) throw new InvalidArgumentException('create_task_only_active_team_member_not_resolved');
        $teamMember = $teamMembers->first();
        return [$teamMember->getMorphClass(), $teamMember->getKey()];
    }

    private function responsibleMorphPair(string $responsibleParty, mixed $responsibleType, mixed $responsibleId, ?string $relatedType, ?int $relatedId): array
    {
        $type = $this->morphType($responsibleType); $id = $this->nullableInt($responsibleId);
        if ($type !== null || $id !== null) {
            if ($type === null || $id === null) throw new InvalidArgumentException('Incomplete task responsible morph.');
            return [$type, $id];
        }
        if ($responsibleParty === Task::RESPONSIBLE_PARTY_CONTACT && $relatedId !== null && $this->isContactMorph($relatedType)) return [$this->morphType(Contact::class), $relatedId];
        return [null, null];
    }

    private function optionalMorphPair(mixed $type, mixed $id, string $field): array
    {
        $normalizedType = $this->morphType($type); $normalizedId = $this->nullableInt($id);
        if ($normalizedType === null && $normalizedId === null) return [null, null];
        if ($normalizedType === null || $normalizedId === null) throw new InvalidArgumentException("Incomplete task {$field} morph.");
        return [$normalizedType, $normalizedId];
    }

    private function responsibleParty(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') return Task::RESPONSIBLE_PARTY_INTERNAL;
        $value = trim($value);
        if (! in_array($value, Task::RESPONSIBLE_PARTY_OPTIONS, true)) throw new InvalidArgumentException("Invalid task responsible party [{$value}].");
        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') throw new InvalidArgumentException("Missing required task field [{$field}].");
        return trim($value);
    }

    private function nullableInt(mixed $value): ?int { return is_numeric($value) ? (int) $value : null; }
    private function nullableString(mixed $value): ?string { return is_string($value) && trim($value) !== '' ? trim($value) : null; }
    private function dueAt(mixed $dueOffsetMinutes): ?CarbonImmutable { $minutes = $this->nullableInt($dueOffsetMinutes); return $minutes === null ? null : CarbonImmutable::now('UTC')->addMinutes($minutes); }

    private function morphType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        $value = trim($value); $mappedModel = Relation::getMorphedModel($value);
        if (is_string($mappedModel) && $this->isModelClass($mappedModel)) return (new $mappedModel())->getMorphClass();
        if ($this->isModelClass($value)) return (new $value())->getMorphClass();
        return $value;
    }

    private function isModelClass(string $class): bool { return class_exists($class) && is_subclass_of($class, Model::class); }
    private function isContactMorph(?string $type): bool { return $type !== null && in_array($type, array_unique([Contact::class, (new Contact())->getMorphClass(), $this->morphType(Contact::class)]), true); }
}
