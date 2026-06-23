<?php

namespace App\Http\Controllers\CRM;

use App\Actions\CRM\Tasks\NotifyAssignedTaskRecipientsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreContactTaskRequest;
use App\Models\Contact;
use App\Models\Task;
use App\Models\TeamMember;
use Illuminate\Http\RedirectResponse;

class ContactTaskController extends Controller
{
    public function store(
        StoreContactTaskRequest $request,
        Contact $contact,
        NotifyAssignedTaskRecipientsAction $notifyAssignedTaskRecipients,
    ): RedirectResponse {
        $validated = $request->validated();

        $task = $contact->tasks()->create([
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => $validated['assigned_to_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'status' => 'open',
        ]);

        if ($request->boolean('notify_assignee')) {
            $notifyAssignedTaskRecipients->handle($task);
        }

        return redirect()->back();
    }

    public function complete(Contact $contact, Task $task): RedirectResponse
    {
        abort_unless($this->taskBelongsToContact($task, $contact), 404);

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $contact->update([
            'last_contacted_at' => now(),
            'last_activity_at' => now(),
        ]);

        return redirect()->back();
    }

    public function reopen(Contact $contact, Task $task): RedirectResponse
    {
        abort_unless($this->taskBelongsToContact($task, $contact), 404);

        $task->update([
            'status' => 'open',
            'completed_at' => null,
        ]);

        return redirect()->back();
    }

    private function taskBelongsToContact(Task $task, Contact $contact): bool
    {
        return $task->related_type === Contact::class
            && (int) $task->related_id === $contact->id;
    }
}