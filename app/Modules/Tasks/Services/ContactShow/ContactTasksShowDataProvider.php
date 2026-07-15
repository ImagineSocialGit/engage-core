<?php

namespace App\Modules\Tasks\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskAssigneeOptionsResolver;
use Illuminate\Database\Eloquent\Builder;

class ContactTasksShowDataProvider implements ContactShowDataProvider
{
    public function __construct(
        private readonly TaskAssigneeOptionsResolver $assigneeOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $taskView = request('task_view') === 'archived' ? 'archived' : 'active';
        $options = $this->assigneeOptions->options(auth()->user());

        return [
            'taskView' => $taskView,

            'tasks' => $this->orderedActiveTaskQuery($contact)->get(),

            'archivedTasks' => $this->contactTaskQuery($contact)
                ->archived()
                ->latest('archived_at')
                ->latest('id')
                ->get(),

            'taskAssigneeOptions' => $options,

            'currentTaskAssigneeKey' => $options
                ->first(fn ($option): bool => $option->isCurrent)
                ?->key(),
        ];
    }

    private function orderedActiveTaskQuery(Contact $contact): Builder
    {
        return $this->contactTaskQuery($contact)
            ->unarchived()
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Task::STATUS_OPEN])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function contactTaskQuery(Contact $contact): Builder
    {
        return Task::query()
            ->with([
                'assignedTo',
                'responsible',
                'links.linkable',
            ])
            ->whereHas('links', function (Builder $query) use ($contact): void {
                $query
                    ->whereIn('linkable_type', $this->contactMorphTypes($contact))
                    ->where('linkable_id', $contact->getKey());
            });
    }

    /**
     * @return array<int, string>
     */
    private function contactMorphTypes(Contact $contact): array
    {
        return array_values(array_unique([
            Contact::class,
            $contact->getMorphClass(),
        ]));
    }
}
