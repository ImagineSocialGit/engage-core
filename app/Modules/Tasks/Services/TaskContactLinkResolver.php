<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;

class TaskContactLinkResolver
{
    public function resolve(Task $task): ?Contact
    {
        $subjectIds = $this->contactIdsForRole($task, TaskLink::ROLE_SUBJECT);

        if (count($subjectIds) === 1) {
            return Contact::query()->find($subjectIds[0]);
        }

        $contactIds = $this->contactIds($task);

        if (count($contactIds) !== 1) {
            return null;
        }

        return Contact::query()->find($contactIds[0]);
    }

    /**
     * @return array<int, int>
     */
    public function contactIds(Task $task): array
    {
        return $this->contactLinksQuery($task)
            ->pluck('linkable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function contactIdsForRole(Task $task, string $role): array
    {
        return $this->contactLinksQuery($task)
            ->where('role', $role)
            ->pluck('linkable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function contactLinksQuery(Task $task)
    {
        $contact = new Contact();

        return $task->links()
            ->whereIn('linkable_type', array_unique([
                Contact::class,
                $contact->getMorphClass(),
            ]));
    }
}
