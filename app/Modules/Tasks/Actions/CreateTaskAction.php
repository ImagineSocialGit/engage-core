<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

class CreateTaskAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data): Task
    {
        [$relatedType, $relatedId] = $this->optionalMorphPair(
            type: $data['related_type'] ?? null,
            id: $data['related_id'] ?? null,
            field: 'related',
        );

        [$assignedToType, $assignedToId] = $this->assignedToMorphPair(
            assignedToType: $data['assigned_to_type'] ?? null,
            assignedToId: $data['assigned_to_id'] ?? null,
        );

        $responsibleParty = $this->responsibleParty($data['responsible_party'] ?? null);

        [$responsibleType, $responsibleId] = $this->responsibleMorphPair(
            responsibleParty: $responsibleParty,
            responsibleType: $data['responsible_type'] ?? null,
            responsibleId: $data['responsible_id'] ?? null,
            relatedType: $relatedType,
            relatedId: $relatedId,
        );

        return Task::query()->create([
            'related_type' => $relatedType,
            'related_id' => $relatedId,

            'assigned_to_type' => $assignedToType,
            'assigned_to_id' => $assignedToId,

            'responsible_party' => $responsibleParty,
            'responsible_type' => $responsibleType,
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

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function assignedToMorphPair(
        mixed $assignedToType,
        mixed $assignedToId,
    ): array {
        $id = $this->nullableInt($assignedToId);

        if ($id === null) {
            return [null, null];
        }

        return [
            $this->morphType($assignedToType) ?? $this->morphType(TeamMember::class),
            $id,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function responsibleMorphPair(
        string $responsibleParty,
        mixed $responsibleType,
        mixed $responsibleId,
        ?string $relatedType,
        ?int $relatedId,
    ): array {
        $type = $this->morphType($responsibleType);
        $id = $this->nullableInt($responsibleId);

        if ($type !== null || $id !== null) {
            if ($type === null || $id === null) {
                throw new InvalidArgumentException('Incomplete task responsible morph.');
            }

            return [$type, $id];
        }

        if (
            $responsibleParty === Task::RESPONSIBLE_PARTY_CONTACT
            && $relatedId !== null
            && $this->isContactMorph($relatedType)
        ) {
            return [
                $this->morphType(Contact::class),
                $relatedId,
            ];
        }

        return [null, null];
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function optionalMorphPair(mixed $type, mixed $id, string $field): array
    {
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

    private function morphType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $mappedModel = Relation::getMorphedModel($value);

        if (is_string($mappedModel) && $this->isModelClass($mappedModel)) {
            return (new $mappedModel())->getMorphClass();
        }

        if ($this->isModelClass($value)) {
            return (new $value())->getMorphClass();
        }

        return $value;
    }

    private function isModelClass(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class);
    }

    private function isContactMorph(?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        return in_array($type, array_unique([
            Contact::class,
            (new Contact())->getMorphClass(),
            $this->morphType(Contact::class),
        ]), true);
    }
}