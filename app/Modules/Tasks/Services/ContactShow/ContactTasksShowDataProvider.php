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

            'tasks' => $this->contactTaskQuery($contact)
                ->unarchived()
                ->latest()
                ->get(),

            'archivedTasks' => $this->contactTaskQuery($contact)
                ->archived()
                ->latest('archived_at')
                ->get(),

            'teamMembers' => TeamMember::active()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),

            'currentTeamMember' => TeamMember::query()
                ->where('user_id', auth()->id())
                ->first(),
        ];
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