<?php

namespace App\Modules\Tasks\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class ContactTasksShowDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $taskView = request('task_view') === 'archived' ? 'archived' : 'active';

        return [
            'taskView' => $taskView,

            'tasks' => $this->orderedActiveTaskQuery($contact)
                ->get(),

            'archivedTasks' => $this->contactTaskQuery($contact)
                ->archived()
                ->latest('archived_at')
                ->latest('id')
                ->get(),

            'teamMembers' => TeamMember::active()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),

            'currentTeamMember' => TeamMember::query()
                ->where('user_id', auth()->id())
                ->first(),
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
            ->with(['assignedTo', 'responsible'])
            ->whereIn('related_type', $this->contactMorphTypes($contact))
            ->where('related_id', $contact->id);
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
